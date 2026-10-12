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
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('omni_queues', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn('campaign_id');
        });
    }
};
