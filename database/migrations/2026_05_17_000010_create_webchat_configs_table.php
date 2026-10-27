<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webchat_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('omni_queue_id');
            $table->uuid('public_token')->unique();
            $table->string('allowed_origin', 255)->nullable();
            $table->string('title', 100)->default('Chat with us');
            $table->string('primary_color', 20)->default('#534AB7');
            $table->string('icon', 50)->default('bi-chat-dots-fill');
            $table->string('position', 2)->default('br');
            $table->text('welcome_message')->nullable();
            $table->text('offline_message')->nullable();
            $table->boolean('interest_enabled')->default(true);
            $table->string('interest_type', 20)->default('dropdown');
            $table->json('interest_options')->nullable();
            $table->json('business_hours')->nullable();
            $table->string('timezone', 50)->default('America/Hermosillo');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('omni_queue_id')->references('id')->on('omni_queues')->cascadeOnDelete();
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webchat_configs');
    }
};
