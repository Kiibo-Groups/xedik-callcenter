<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->enum('disposition', [
                'customer',      // Customer confirmed / converted
                'callback',      // Needs callback
                'no_answer',     // No answer
                'voicemail',     // Left voicemail
                'wrong_number',  // Wrong number
                'not_interested',// Not interested
                'busy',          // Line busy
                'disconnected',  // Disconnected line
            ])->default('no_answer');
            $table->dateTime('called_at');
            $table->integer('duration')->nullable()->comment('Duration in seconds');
            $table->text('notes')->nullable();
            $table->dateTime('scheduled_at')->nullable()->comment('For callbacks - when to call back');
            $table->boolean('is_scheduled')->default(false)->comment('True if this is a scheduled call');
            $table->boolean('completed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
