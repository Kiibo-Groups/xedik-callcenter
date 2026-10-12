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
        // Make user_id nullable so voice_extensions survive user deletion.
        // The PHP cleanup in UserController::destroy() sets user_id=null
        // BEFORE $user->delete(), so the cascadeOnDelete on the FK never fires.
        // In MySQL production: also change the FK itself to SET NULL for defence-in-depth.
        Schema::table('voice_extensions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->timestamp('released_at')->nullable()->after('sip_provisioning_error');
        });

        // For MySQL/MariaDB: also update the FK constraint to nullOnDelete.
        // SQLite does not enforce FK constraints by default in development;
        // the PHP-side cleanup makes this moot for SQLite.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('voice_extensions', function (Blueprint $table) {
                $table->dropForeign('voice_extensions_user_id_foreign');
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('voice_extensions', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });

        Schema::table('voice_extensions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('voice_extensions', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }
};
