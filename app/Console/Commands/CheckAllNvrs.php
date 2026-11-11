<?php

namespace App\Console\Commands;

use App\Jobs\CheckNvrReachability;
use App\Models\NvrDevice;
use Illuminate\Console\Command;

class CheckAllNvrs extends Command
{
    protected $signature = 'nvr:check-all';

    protected $description = 'Queue a reachability check for every enabled NVR';

    public function handle(): int
    {
        $queued = 0;

        NvrDevice::query()
            ->where('enabled', true)
            ->select('id')
            ->chunkById(200, function ($devices) use (&$queued): void {
                foreach ($devices as $device) {
                    CheckNvrReachability::dispatch($device->id);
                    $queued++;
                }
            });

        $this->info("Queued reachability checks for {$queued} enabled NVR(s).");

        return self::SUCCESS;
    }
}
