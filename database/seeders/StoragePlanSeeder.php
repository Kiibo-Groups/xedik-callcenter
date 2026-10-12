<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StoragePlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code'           => 'included_1gb',
                'name'           => 'Incluido (1 GB)',
                'included_bytes' => 1_073_741_824,
                'price_monthly'  => 0.00,
                'currency'       => 'USD',
                'is_active'      => true,
            ],
            [
                'code'           => 'extra_10gb',
                'name'           => 'Extra 10 GB',
                'included_bytes' => 10_737_418_240,
                'price_monthly'  => 5.00,
                'currency'       => 'USD',
                'is_active'      => true,
            ],
            [
                'code'           => 'extra_50gb',
                'name'           => 'Extra 50 GB',
                'included_bytes' => 53_687_091_200,
                'price_monthly'  => 15.00,
                'currency'       => 'USD',
                'is_active'      => true,
            ],
            [
                'code'           => 'extra_100gb',
                'name'           => 'Extra 100 GB',
                'included_bytes' => 107_374_182_400,
                'price_monthly'  => 25.00,
                'currency'       => 'USD',
                'is_active'      => true,
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('storage_plans')->updateOrInsert(
                ['code' => $plan['code']],
                array_merge($plan, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
