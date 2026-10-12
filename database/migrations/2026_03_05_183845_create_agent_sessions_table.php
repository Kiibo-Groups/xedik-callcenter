<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->enum('status', ['online', 'away', 'offline'])->default('offline');
            $table->unsignedInteger('active_emails')->default(0);
            $table->unsignedInteger('active_chats')->default(0);
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sessions');
    }
};