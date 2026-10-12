<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_id')->constrained('omni_queues')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('shortcut', 50)->nullable();
            $table->enum('channel', ['email', 'chat', 'all'])->default('all');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_template')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
    }
};
