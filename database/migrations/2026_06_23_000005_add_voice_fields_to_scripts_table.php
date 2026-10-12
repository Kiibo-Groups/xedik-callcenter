<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            // 'both' = suitable for inbound and outbound; used to filter in the voice dialer
            $table->string('direction', 20)->default('both')->after('is_active');
            // One default outbound script per campaign (enforced at application level)
            $table->boolean('is_default')->default(false)->after('direction');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_default');

            $table->index(['campaign_id', 'direction', 'is_active'], 'scripts_camp_dir_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropIndex('scripts_camp_dir_active_idx');
            $table->dropColumn(['direction', 'is_default', 'sort_order']);
        });
    }
};
