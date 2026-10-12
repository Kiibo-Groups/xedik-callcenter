<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_provider_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('driver', 20);
            $table->boolean('requires_endpoint')->default(false);
            $table->boolean('requires_region')->default(false);
            $table->boolean('supports_temporary_urls')->default(true);
            $table->boolean('supports_private_buckets')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_provider_catalog');
    }
};
