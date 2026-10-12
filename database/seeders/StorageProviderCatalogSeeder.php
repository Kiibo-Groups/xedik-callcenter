<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StorageProviderCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            [
                'code'                    => 'cloudflare_r2',
                'name'                    => 'Cloudflare R2',
                'driver'                  => 's3',
                'requires_endpoint'       => true,
                'requires_region'         => false,
                'supports_temporary_urls' => true,
                'supports_private_buckets'=> true,
                'is_active'               => true,
            ],
            [
                'code'                    => 'amazon_s3',
                'name'                    => 'Amazon S3',
                'driver'                  => 's3',
                'requires_endpoint'       => false,
                'requires_region'         => true,
                'supports_temporary_urls' => true,
                'supports_private_buckets'=> true,
                'is_active'               => true,
            ],
            [
                'code'                    => 'minio',
                'name'                    => 'MinIO / S3-Compatible',
                'driver'                  => 's3',
                'requires_endpoint'       => true,
                'requires_region'         => false,
                'supports_temporary_urls' => true,
                'supports_private_buckets'=> true,
                'is_active'               => true,
            ],
            [
                'code'                    => 'local',
                'name'                    => 'Local (solo desarrollo)',
                'driver'                  => 'local',
                'requires_endpoint'       => false,
                'requires_region'         => false,
                'supports_temporary_urls' => false,
                'supports_private_buckets'=> false,
                'is_active'               => true,
            ],
        ];

        foreach ($providers as $provider) {
            DB::table('storage_provider_catalog')->updateOrInsert(
                ['code' => $provider['code']],
                array_merge($provider, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
