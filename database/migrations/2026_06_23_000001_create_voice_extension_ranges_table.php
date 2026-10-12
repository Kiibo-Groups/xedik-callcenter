<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_extension_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('range_start');
            $table->unsignedSmallInteger('range_end');
            $table->string('label', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            // Prevents two ranges with same start within the same tenant
            $table->unique(['tenant_id', 'range_start'], 'ver_tenant_start_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_extension_ranges');
    }
};
