<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_storage_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('provider_code', 50);
            $table->string('environment', 20)->default('production');
            $table->string('bucket', 255);
            $table->string('region', 50)->default('auto');
            $table->string('endpoint', 500)->nullable();
            $table->string('base_path', 255)->nullable();
            $table->text('access_key_encrypted');
            $table->text('secret_key_encrypted');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('provider_code');
            $table->index('environment');
            $table->index(['is_default', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_storage_profiles');
    }
};
