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

        $columns = [
            'button_label'    => "VARCHAR(100) DEFAULT 'Chat with us'",
            'window_title'    => "VARCHAR(100) DEFAULT 'Chat with us'",
            'window_subtitle' => "VARCHAR(150) DEFAULT 'We typically reply in 2 min'",
            'form_subtitle'   => "VARCHAR(200) DEFAULT 'Before we start, tell us about you.'",
        ];

        if ($driver === 'sqlite') {
            foreach ($columns as $col => $def) {
                if (!Schema::hasColumn('webchat_configs', $col)) {
                    DB::statement("ALTER TABLE webchat_configs ADD COLUMN {$col} {$def}");
                }
            }
            // Copy title → window_title and button_label if title exists
            if (Schema::hasColumn('webchat_configs', 'title')) {
                DB::statement("UPDATE webchat_configs SET window_title = title, button_label = title WHERE title IS NOT NULL AND title != ''");
            }
        } else {
            Schema::table('webchat_configs', function (Blueprint $table) {
                if (!Schema::hasColumn('webchat_configs', 'button_label')) {
                    $table->string('button_label', 100)->default('Chat with us')->after('title');
                }
                if (!Schema::hasColumn('webchat_configs', 'window_title')) {
                    $table->string('window_title', 100)->default('Chat with us')->after('button_label');
                }
                if (!Schema::hasColumn('webchat_configs', 'window_subtitle')) {
                    $table->string('window_subtitle', 150)->default('We typically reply in 2 min')->after('window_title');
                }
                if (!Schema::hasColumn('webchat_configs', 'form_subtitle')) {
                    $table->string('form_subtitle', 200)->default('Before we start, tell us about you.')->after('window_subtitle');
                }
            });

            // Copy title values
            if (Schema::hasColumn('webchat_configs', 'title')) {
                DB::statement("UPDATE webchat_configs SET window_title = title, button_label = title WHERE title IS NOT NULL AND title != ''");
                Schema::table('webchat_configs', function (Blueprint $table) {
                    $table->dropColumn('title');
                });
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        Schema::table('webchat_configs', function (Blueprint $table) {
            $table->dropColumn(['button_label', 'window_title', 'window_subtitle', 'form_subtitle']);
        });
    }
};
