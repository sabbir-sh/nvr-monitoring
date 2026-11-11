<?php

namespace Database\Seeders;

use App\Models\NvrDevice;
use Illuminate\Database\Seeder;

class NvrDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        for ($number = 1; $number <= 8 && NvrDevice::count() < 8; $number++) {
            $ip = '192.0.2.'.(100 + $number);

            NvrDevice::firstOrCreate(
                ['ip' => $ip, 'port' => 80],
                [
                    'name' => sprintf('Demo NVR %02d', $number),
                    'site' => 'Demo sample',
                    'vendor' => 'Sample',
                    'status' => 'unknown',
                    'enabled' => false,
                ],
            );
        }
    }
}
