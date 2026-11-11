<?php

namespace Tests\Feature;

use App\Jobs\CheckNvrReachability;
use App\Models\NvrDevice;
use App\Models\NvrHealthCheck;
use App\Services\TcpPortProbe;
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
        NvrDevice::create([
            'name' => 'Main Gate',
            'ip' => '192.168.1.201',
            'port' => 80,
            'status' => 'online',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Device inventory');

        $this->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.online', 1)
            ->assertJsonPath('devices.0.name', 'Main Gate');
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
        $probe = Mockery::mock(TcpPortProbe::class);
        $probe->shouldReceive('check')->once()->with($device->ip, $device->port)->andReturn([
            'online' => true,
            'latency_ms' => 24,
            'message' => null,
        ]);

        (new CheckNvrReachability($device->id))->handle($probe);

        $this->assertDatabaseHas('nvr_devices', ['id' => $device->id, 'status' => 'online', 'latency_ms' => 24]);
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
