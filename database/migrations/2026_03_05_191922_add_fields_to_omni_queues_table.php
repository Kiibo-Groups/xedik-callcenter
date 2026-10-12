<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omni_queues', function (Blueprint $table) {
            if (!Schema::hasColumn('omni_queues', 'name')) {
                $table->string('name')->after('id');
            }
            if (!Schema::hasColumn('omni_queues', 'type')) {
                $table->enum('type', ['email', 'chat', 'omni'])->default('email')->after('name');
            }
            if (!Schema::hasColumn('omni_queues', 'assignment_mode')) {
                $table->enum('assignment_mode', ['round_robin', 'least_busy'])->default('round_robin')->after('type');
            }
            if (!Schema::hasColumn('omni_queues', 'max_wait_minutes')) {
                $table->unsignedInteger('max_wait_minutes')->default(30)->after('assignment_mode');
            }
            if (!Schema::hasColumn('omni_queues', 'active')) {
                $table->boolean('active')->default(true)->after('max_wait_minutes');
            }
        });
    }

    public function down(): void
    {
        // Current create_omni_queues_table already defines these columns.
        // Keep rollback non-destructive for this compatibility migration.
    }
};
