<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omni_queue_voice_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('omni_queue_id')->constrained('omni_queues')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('asterisk_queue_name', 128);
            $table->string('strategy', 32)->default('rrmemory');
            $table->unsignedInteger('timeout')->default(20);
            $table->unsignedInteger('retry')->default(5);
            $table->unsignedInteger('wrapup_time')->default(10);
            $table->unsignedInteger('maxlen')->default(0);
            $table->string('music_on_hold', 128)->default('default');
            $table->unsignedInteger('service_level')->default(60);
            $table->integer('weight')->nullable();
            $table->boolean('ring_in_use')->default(false);
            $table->boolean('autopause')->default(false);
            $table->string('sync_status', 20)->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('omni_queue_id');
            $table->unique('asterisk_queue_name');
            $table->index(['tenant_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omni_queue_voice_settings');
    }
};
