<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('endpoint_catalog')->get()->each(function (object $ep) {
            $changes = [];

            // url → keep only the path (strip scheme+host)
            if (!empty($ep->url)) {
                $path = parse_url($ep->url, PHP_URL_PATH) ?? $ep->url;
                if ($path !== $ep->url) {
                    $changes['url'] = $path;
                }
            }

            // example → replace any http(s)://host with {base_url}
            if (!empty($ep->example)) {
                $normalized = preg_replace('~https?://[^/\s"\']+~', '{base_url}', $ep->example);
                if ($normalized !== $ep->example) {
                    $changes['example'] = $normalized;
                }
            }

            if ($changes) {
                DB::table('endpoint_catalog')->where('id', $ep->id)->update($changes);
            }
        });
    }

    public function down(): void
    {
        // Cannot recover original domain — intentional
    }
};
