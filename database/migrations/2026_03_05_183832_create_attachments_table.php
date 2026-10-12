<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            if (Schema::hasTable('messages')) {
                $table->foreignId('message_id')
                      ->constrained('messages')
                      ->cascadeOnDelete();
            } else {
                $table->unsignedBigInteger('message_id')->index();
            }
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
