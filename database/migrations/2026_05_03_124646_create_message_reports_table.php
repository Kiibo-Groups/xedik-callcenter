<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_report_id')->constrained('conversation_reports')->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound'])->default('inbound');
            $table->string('from_email')->nullable();
            $table->string('to_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('conversation_report_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_reports');
    }
};
