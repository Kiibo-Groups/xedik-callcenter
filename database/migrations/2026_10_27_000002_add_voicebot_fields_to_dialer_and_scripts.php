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
        if (!Schema::hasColumn('dialer_campaigns', 'voicebot_agent_id')) {
            Schema::table('dialer_campaigns', function (Blueprint $table) {
                $table->string('voicebot_agent_id', 120)->nullable()->after('context');
            });
        }

        if (!Schema::hasColumn('scripts', 'voicebot_agent_id')) {
            Schema::table('scripts', function (Blueprint $table) {
                $table->string('voicebot_agent_id', 120)->nullable()->after('sort_order');
                $table->string('voicebot_voice_id', 120)->nullable()->after('voicebot_agent_id');
            });
        }

        // Backfill existing records dynamically
        DB::table('scripts')
            ->where('id', 4)
            ->update([
                'voicebot_agent_id' => 'agent_2101m5xst5cgf1gv86jxhf2an2nd',
                'voicebot_voice_id' => 'p7sm2zw5Us66lZr2brDW',
            ]);

        DB::table('scripts')
            ->where('id', 3)
            ->update([
                'voicebot_agent_id' => 'agent_0001m5h3zv32egsb2mfmgnd6a395',
                'voicebot_voice_id' => 'ojGau167OE7nUATM1xDa',
            ]);

        DB::table('dialer_campaigns')
            ->where('id', 9)
            ->update([
                'voicebot_agent_id' => 'agent_2101m5xst5cgf1gv86jxhf2an2nd',
            ]);

        DB::table('dialer_campaigns')
            ->where('id', 8)
            ->update([
                'voicebot_agent_id' => 'agent_0001m5h3zv32egsb2mfmgnd6a395',
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('dialer_campaigns', 'voicebot_agent_id')) {
            Schema::table('dialer_campaigns', function (Blueprint $table) {
                $table->dropColumn('voicebot_agent_id');
            });
        }

        if (Schema::hasColumn('scripts', 'voicebot_agent_id')) {
            Schema::table('scripts', function (Blueprint $table) {
                $table->dropColumn(['voicebot_agent_id', 'voicebot_voice_id']);
            });
        }
    }
};
