<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ManagedStorageProfileSeeder extends Seeder
{
    public function run(): void
    {
        // Perfil dev — lee del .env actual
        $devKey    = env('R2_ACCESS_KEY_ID', '');
        $devSecret = env('R2_SECRET_ACCESS_KEY', '');
        $devBucket = env('R2_BUCKET', '');
        $devEndpoint = env('R2_ENDPOINT', '');

        if ($devKey && $devSecret && $devBucket) {
            DB::table('managed_storage_profiles')->updateOrInsert(
                ['code' => 'r2_dev'],
                [
                    'name'                 => 'Cloudflare R2 — Dev',
                    'provider_code'        => 'cloudflare_r2',
                    'environment'          => 'dev',
                    'bucket'               => $devBucket,
                    'region'               => 'auto',
                    'endpoint'             => $devEndpoint ?: null,
                    'base_path'            => null,
                    'access_key_encrypted' => Crypt::encryptString($devKey),
                    'secret_key_encrypted' => Crypt::encryptString($devSecret),
                    'is_default'           => false,
                    'is_active'            => true,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]
            );
        }

        // Perfil producción — se configura desde la UI del superadmin
        // Las credenciales se dejan vacías; el seeder solo crea el registro placeholder
        DB::table('managed_storage_profiles')->updateOrInsert(
            ['code' => 'r2_production'],
            [
                'name'                 => 'Cloudflare R2 — Producción (default)',
                'provider_code'        => 'cloudflare_r2',
                'environment'          => 'production',
                'bucket'               => 'crm-files-prod',
                'region'               => 'auto',
                'endpoint'             => null,
                'base_path'            => null,
                'access_key_encrypted' => Crypt::encryptString('PENDING_CONFIGURATION'),
                'secret_key_encrypted' => Crypt::encryptString('PENDING_CONFIGURATION'),
                'is_default'           => true,
                'is_active'            => false,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]
        );
    }
}
