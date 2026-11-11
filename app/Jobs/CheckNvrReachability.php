<?php

namespace App\Jobs;

use App\Models\NvrDevice;
use App\Models\NvrHealthCheck;
use App\Services\TcpPortProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckNvrReachability implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 5;

    public int $uniqueFor = 50;

    public function __construct(public int $deviceId)
    {
        $this->onQueue('nvr-health');
    }

    public function uniqueId(): string
    {
        return (string) $this->deviceId;
    }

    public function handle(TcpPortProbe $probe): void
    {
        $device = NvrDevice::find($this->deviceId);

        if (! $device || ! $device->enabled) {
            return;
        }

        $result = $probe->check($device->ip, $device->port);
        $checkedAt = now();
        $status = $result['online'] ? 'online' : 'offline';

        $device->update([
            'status' => $status,
            'latency_ms' => $result['latency_ms'],
            'last_checked_at' => $checkedAt,
            'last_error' => $result['message'] ? mb_substr($result['message'], 0, 255) : null,
        ]);

        NvrHealthCheck::create([
            'nvr_device_id' => $device->id,
            'status' => $status,
            'latency_ms' => $result['latency_ms'],
            'message' => $result['message'],
            'checked_at' => $checkedAt,
        ]);
    }
}
