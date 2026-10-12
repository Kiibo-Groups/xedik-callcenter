<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoint_catalog', function (Blueprint $table) {
            $table->string('group', 80)->nullable()->after('method');
        });

        // All current endpoints belong to Lead Lists group
        DB::table('endpoint_catalog')->update(['group' => 'Lead Lists']);

        // Update response_example: ok → success
        DB::table('endpoint_catalog')->get()->each(function (object $ep) {
            if (empty($ep->response_example)) return;

            $updated = str_replace(
                ['"ok":true', '"ok":false', '"ok": true', '"ok": false'],
                ['"success":true', '"success":false', '"success": true', '"success": false'],
                $ep->response_example
            );

            if ($updated !== $ep->response_example) {
                DB::table('endpoint_catalog')->where('id', $ep->id)
                    ->update(['response_example' => $updated]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('endpoint_catalog', function (Blueprint $table) {
            $table->dropColumn('group');
        });
    }
};
