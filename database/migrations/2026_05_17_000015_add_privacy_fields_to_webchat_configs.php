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
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            if (!Schema::hasColumn('webchat_configs', 'privacy_enabled')) {
                DB::statement("ALTER TABLE webchat_configs ADD COLUMN privacy_enabled BOOLEAN DEFAULT 0");
            }
            if (!Schema::hasColumn('webchat_configs', 'privacy_type')) {
                DB::statement("ALTER TABLE webchat_configs ADD COLUMN privacy_type VARCHAR(20) DEFAULT 'info'");
            }
            if (!Schema::hasColumn('webchat_configs', 'privacy_text')) {
                DB::statement("ALTER TABLE webchat_configs ADD COLUMN privacy_text TEXT DEFAULT NULL");
            }
            if (!Schema::hasColumn('webchat_configs', 'privacy_url')) {
                DB::statement("ALTER TABLE webchat_configs ADD COLUMN privacy_url VARCHAR(255) DEFAULT NULL");
            }
        } else {
            Schema::table('webchat_configs', function (Blueprint $table) {
                if (!Schema::hasColumn('webchat_configs', 'privacy_enabled')) {
                    $table->boolean('privacy_enabled')->default(false);
                }
                if (!Schema::hasColumn('webchat_configs', 'privacy_type')) {
                    $table->string('privacy_type', 20)->default('info');
                }
                if (!Schema::hasColumn('webchat_configs', 'privacy_text')) {
                    $table->text('privacy_text')->nullable();
                }
                if (!Schema::hasColumn('webchat_configs', 'privacy_url')) {
                    $table->string('privacy_url', 255)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        Schema::table('webchat_configs', function (Blueprint $table) {
            $table->dropColumn(['privacy_enabled', 'privacy_type', 'privacy_text', 'privacy_url']);
        });
    }
};
