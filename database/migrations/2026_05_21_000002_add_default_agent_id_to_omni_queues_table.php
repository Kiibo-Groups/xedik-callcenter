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
            $table->foreignId('default_agent_id')
                  ->nullable()
                  ->after('assignment_mode')
                  ->constrained('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('omni_queues', function (Blueprint $table) {
            $table->dropForeign(['default_agent_id']);
            $table->dropColumn('default_agent_id');
        });
    }
};
