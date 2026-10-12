<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->enum('channel', ['email', 'chat'])->default('email');
            if (Schema::hasTable('email_accounts')) {
                $table->foreignId('email_account_id')
                      ->nullable()
                      ->constrained('email_accounts')
                      ->nullOnDelete();
            } else {
                $table->unsignedBigInteger('email_account_id')->nullable();
            }
            $table->foreignId('omni_queue_id')
                  ->nullable()
                  ->constrained('omni_queues')
                  ->nullOnDelete();
            $table->foreignId('assigned_to')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('lead_id')
                  ->nullable()
                  ->constrained('leads')
                  ->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->enum('status', ['pending', 'active', 'resolved', 'archived'])->default('pending');
            $table->timestamp('wait_since')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'assigned_to']);
            $table->index(['email_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
