<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omni_queues', function (Blueprint $table) {
            $table->foreignId('script_id')->nullable()->constrained('scripts')->nullOnDelete()->after('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('omni_queues', function (Blueprint $table) {
            $table->dropForeign(['script_id']);
            $table->dropColumn('script_id');
        });
    }
};
