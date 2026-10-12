<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->unique();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('agent_name', 100)->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('lead_name', 200)->nullable();
            $table->string('contact_email', 200)->nullable();
            $table->string('channel', 20)->default('email');
            $table->string('direction', 20)->nullable();
            $table->string('subject', 500)->nullable();
            $table->text('first_message_preview')->nullable();
            $table->string('disposition', 200)->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('wait_since')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('agent_id');
            $table->index('channel');
            $table->index('direction');
            $table->index(['resolved_at', 'agent_id']);
            $table->index(['wait_since', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reports');
    }
};
