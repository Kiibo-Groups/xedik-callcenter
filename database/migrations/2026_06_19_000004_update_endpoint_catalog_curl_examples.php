<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $base = rtrim(config('app.url', 'https://your-domain.com'), '/');

        DB::table('endpoint_catalog')->where('name', 'Lead View / Screen Pop')->update([
            'example' => implode(" \\\n", [
                'curl -X GET',
                '  "' . $base . '/dialer/lead-view?phone=6025551234&lead_list_id=1&token={token}&view=json"',
            ]),
        ]);

        DB::table('endpoint_catalog')->where('name', 'Add Lead to List')->update([
            'example' => implode(" \\\n", [
                'curl -X POST',
                '  "' . $base . '/api/lead-lists/1/leads?token={token}"',
                '  -H "Content-Type: application/json"',
                '  -d \'{"phone":"6025551234","custom_fields":{"company":"Acme Corp","state":"AZ"}}\'',
            ]),
        ]);

        DB::table('endpoint_catalog')->where('name', 'Update Lead')->update([
            'example' => implode(" \\\n", [
                'curl -X PUT',
                '  "' . $base . '/api/lead-lists/1/leads/6025551234?token={token}"',
                '  -H "Content-Type: application/json"',
                '  -d \'{"custom_fields":{"disposition":"callback","notes":"Call back Monday"}}\'',
            ]),
        ]);

        DB::table('endpoint_catalog')->where('name', 'Delete Lead')->update([
            'example' => implode(" \\\n", [
                'curl -X DELETE',
                '  "' . $base . '/api/lead-lists/1/leads/6025551234?token={token}"',
            ]),
        ]);
    }

    public function down(): void {}
};
