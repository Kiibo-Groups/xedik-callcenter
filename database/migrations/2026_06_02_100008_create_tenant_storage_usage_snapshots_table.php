<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_storage_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('used_bytes');
            $table->unsignedBigInteger('allowed_bytes');
            $table->decimal('usage_percent', 5, 2);
            $table->unsignedInteger('active_files_count');
            $table->date('snapshot_date');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_storage_usage_snapshots');
    }
};
