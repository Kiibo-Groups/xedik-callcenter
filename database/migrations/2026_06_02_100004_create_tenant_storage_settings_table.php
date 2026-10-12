<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider_code', 50);
            $table->string('ownership_type', 20)->default('crm_managed');
            $table->foreignId('managed_storage_profile_id')->nullable()->constrained('managed_storage_profiles')->nullOnDelete();
            $table->string('bucket', 255)->nullable();
            $table->string('region', 50)->nullable();
            $table->string('endpoint', 500)->nullable();
            $table->string('base_path', 255)->nullable();
            $table->text('access_key_encrypted')->nullable();
            $table->text('secret_key_encrypted')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_connection_test_at')->nullable();
            $table->string('last_connection_status', 20)->nullable();
            $table->text('last_connection_error')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->index('provider_code');
            $table->index('ownership_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_storage_settings');
    }
};
