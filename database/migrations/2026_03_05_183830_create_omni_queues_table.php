<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omni_queues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['email', 'chat', 'omni'])->default('email');
            $table->enum('assignment_mode', ['round_robin', 'least_busy'])->default('round_robin');
            $table->unsignedInteger('max_wait_minutes')->default(30);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omni_queues');
    }
};