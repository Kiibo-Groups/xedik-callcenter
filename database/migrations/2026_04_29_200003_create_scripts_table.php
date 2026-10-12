<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('name', 150);
            $table->enum('type', ['builder', 'url'])->default('builder');
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->json('url_params')->nullable();
            $table->enum('url_target', ['tab', 'panel', 'modal'])->default('tab');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scripts');
    }
};
