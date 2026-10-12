<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omni_queue_user', function (Blueprint $table) {
            $table->id();
            if (Schema::hasTable('omni_queues')) {
                $table->foreignId('omni_queue_id')->constrained('omni_queues')->cascadeOnDelete();
            } else {
                $table->unsignedBigInteger('omni_queue_id')->index();
            }
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('max_emails')->default(3);
            $table->unsignedInteger('max_chats')->default(1);
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['omni_queue_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omni_queue_user');
    }
};
