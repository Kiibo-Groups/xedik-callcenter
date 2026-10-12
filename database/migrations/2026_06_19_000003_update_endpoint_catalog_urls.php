<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $base = config('app.url', 'https://your-domain.com');

        // Fix URLs: /api/external/ → /api/  and PATCH → PUT
        DB::table('endpoint_catalog')
            ->where('name', 'Add Lead to List')
            ->update(['url' => $base . '/api/lead-lists/{lead_list_id}/leads']);

        DB::table('endpoint_catalog')
            ->where('name', 'Update Lead')
            ->update([
                'method' => 'PUT',
                'url'    => $base . '/api/lead-lists/{lead_list_id}/leads/{phone}',
                'example'=> 'PUT ' . $base . '/api/lead-lists/1/leads/6025551234?token={token}' . "\n"
                          . 'Content-Type: application/json' . "\n\n"
                          . '{"custom_fields":{"disposition":"callback","notes":"Interested, call back Monday"}}',
            ]);

        DB::table('endpoint_catalog')
            ->where('name', 'Delete Lead')
            ->update([
                'url'    => $base . '/api/lead-lists/{lead_list_id}/leads/{phone}',
                'example'=> 'DELETE ' . $base . '/api/lead-lists/1/leads/6025551234?token={token}',
            ]);
    }

    public function down(): void {}
};
