<?php

namespace Tests\Feature;

use App\Jobs\CheckNvrReachability;
use App\Models\NvrDevice;
use App\Models\NvrHealthCheck;
use App\Services\PingProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class NvrMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_inventory_and_summary(): void
    {
        Queue::fake();
        NvrDevice::create([
            'name' => 'Main Gate',
            'ip' => '192.168.1.201',
            'port' => 80,
            'status' => 'online',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Device inventory')
            ->assertDontSee('TCP PORT (INFO)');

        $this->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.online', 1)
            ->assertJsonPath('devices.0.name', 'Main Gate');

        Queue::assertPushed(CheckNvrReachability::class, fn (CheckNvrReachability $job) => $job->deviceId === 1);
    }

    public function test_device_inventory_has_a_separate_compact_grid_page(): void
    {
        $this->get('/devices/inventory')
            ->assertOk()
            ->assertSee('Device inventory')
            ->assertSee('id="primary-nav"', false)
            ->assertDontSee('class="sidebar"', false)
            ->assertSee('id="device-grid"', false)
            ->assertSee('All recorders')
            ->assertDontSee('NETWORK OPERATIONS')
            ->assertSee('id="add-device-button"', false)
            ->assertDontSee('id="device-rows"', false);
    }

    public function test_device_inventory_returns_eight_devices_per_page(): void
    {
        Queue::fake();
        for ($number = 1; $number <= 9; $number++) {
            NvrDevice::create([
                'name' => sprintf('Recorder %02d', $number),
                'ip' => '192.168.1.'.(200 + $number),
                'port' => 80,
            ]);
        }

        $this->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonCount(8, 'devices')
            ->assertJsonPath('pagination.per_page', 8);
    }

    public function test_demo_seeder_fills_inventory_to_eight_without_overwriting_existing_devices(): void
    {
        $existing = NvrDevice::create([
            'name' => 'My Recorder',
            'ip' => '192.168.1.201',
            'port' => 80,
            'status' => 'online',
        ]);

        $this->seed(\Database\Seeders\NvrDemoSeeder::class);
        $this->seed(\Database\Seeders\NvrDemoSeeder::class);

        $this->assertDatabaseCount('nvr_devices', 8);
        $this->assertDatabaseHas('nvr_devices', [
            'id' => $existing->id,
            'name' => 'My Recorder',
            'ip' => '192.168.1.201',
            'status' => 'online',
        ]);
        $this->assertDatabaseHas('nvr_devices', [
            'ip' => '192.0.2.101',
            'enabled' => false,
        ]);
    }

    public function test_invalid_ip_is_rejected_when_creating_a_device(): void
    {
        $this->postJson('/devices', [
            'name' => 'Invalid recorder',
            'ip' => 'not-an-ip',
            'port' => 80,
            'enabled' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['ip']);
    }

    public function test_manual_check_is_queued(): void
    {
        Queue::fake();
        $device = NvrDevice::create(['name' => 'Recorder', 'ip' => '192.168.1.202', 'port' => 80]);

        $this->postJson("/devices/{$device->id}/check")
            ->assertStatus(202);

        Queue::assertPushed(CheckNvrReachability::class, fn (CheckNvrReachability $job) => $job->deviceId === $device->id);
    }

    public function test_health_job_updates_device_and_records_history(): void
    {
        $device = NvrDevice::create(['name' => 'Recorder', 'ip' => '192.168.1.203', 'port' => 8000]);
        $probe = Mockery::mock(PingProbe::class);
        $probe->shouldReceive('check')->once()->with($device->ip)->andReturn([
            'online' => true,
            'latency_ms' => 24,
            'message' => null,
        ]);

        (new CheckNvrReachability($device->id))->handle($probe);

        $this->assertDatabaseHas('nvr_devices', ['id' => $device->id, 'status' => 'online', 'latency_ms' => 24]);
        $this->assertSame(1, NvrHealthCheck::where('nvr_device_id', $device->id)->count());
    }

    public function test_ping_probe_marks_a_device_online_after_any_target_reply(): void
    {
        $probe = new PingProbe;
        $reply = "Reply from 192.168.0.196: bytes=32 time=4ms TTL=64\n"
            ."Packets: Sent = 1, Received = 1, Lost = 0 (0% loss)\n"
            ."Approximate round trip times in milli-seconds: Minimum = 4ms, Maximum = 4ms, Average = 4ms";

        $this->assertSame([
            'online' => true,
            'latency_ms' => 4,
            'message' => null,
        ], $probe->parseOutput($reply, '192.168.0.196'));
    }

    public function test_router_unreachable_replies_do_not_mark_target_online(): void
    {
        $probe = new PingProbe;
        $output = "Reply from 192.168.0.194: Destination host unreachable.\n"
            ."Reply from 192.168.0.194: Destination host unreachable.\n"
            ."Packets: Sent = 1, Received = 1, Lost = 0 (0% loss)";

        $this->assertSame([
            'online' => false,
            'latency_ms' => null,
            'message' => 'No ping response from 192.168.0.196.',
        ], $probe->parseOutput($output, '192.168.0.196'));
    }

    public function test_incomplete_ping_keeps_an_offline_device_offline(): void
    {
        $device = NvrDevice::create([
            'name' => 'Recorder',
            'ip' => '192.168.1.205',
            'port' => 80,
            'status' => 'offline',
        ]);
        $probe = Mockery::mock(PingProbe::class);
        $probe->shouldReceive('check')->once()->with($device->ip)->andReturn([
            'online' => false,
            'latency_ms' => null,
            'message' => 'Incomplete ping response (1 of 3 replies from target).',
        ]);

        (new CheckNvrReachability($device->id))->handle($probe);

        $this->assertDatabaseHas('nvr_devices', [
            'id' => $device->id,
            'status' => 'offline',
            'latency_ms' => null,
            'last_error' => 'Incomplete ping response (1 of 3 replies from target).',
        ]);
    }

    public function test_repeated_health_checks_only_record_status_changes(): void
    {
        $device = NvrDevice::create(['name' => 'Recorder', 'ip' => '192.168.1.204', 'port' => 80]);
        $probe = Mockery::mock(PingProbe::class);
        $probe->shouldReceive('check')->twice()->andReturn([
            'online' => true,
            'latency_ms' => 24,
            'message' => null,
        ]);

        (new CheckNvrReachability($device->id))->handle($probe);
        (new CheckNvrReachability($device->id))->handle($probe);

        $this->assertSame(1, NvrHealthCheck::where('nvr_device_id', $device->id)->count());
    }

    public function test_csv_import_creates_devices(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'nvr-list.csv',
            "name,ip,port,site,vendor,channels\nWarehouse,192.168.1.220,8000,Warehouse,Hikvision,16\n",
        );

        $this->postJson('/devices/import', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('count', 1);

        $this->assertDatabaseHas('nvr_devices', [
            'name' => 'Warehouse',
            'ip' => '192.168.1.220',
            'port' => 8000,
            'site' => 'Warehouse',
        ]);
    }

    public function test_check_all_queues_enabled_devices_only(): void
    {
        Queue::fake();
        $enabled = NvrDevice::create(['name' => 'Enabled', 'ip' => '192.168.1.230', 'port' => 80]);
        NvrDevice::create(['name' => 'Disabled', 'ip' => '192.168.1.231', 'port' => 80, 'enabled' => false]);

        $this->postJson('/devices/check-all')
            ->assertStatus(202)
            ->assertJsonPath('count', 1);

        Queue::assertPushed(CheckNvrReachability::class, 1);
        Queue::assertPushed(CheckNvrReachability::class, fn (CheckNvrReachability $job) => $job->deviceId === $enabled->id);
    }
}
