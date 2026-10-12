<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_tenant_settings', function (Blueprint $table) {
            // Default OmniQueue for inbound Voice conversations.
            // Optional — when null, omni_queue_id stays null in the Conversation.
            // Outbound resolves via Lead/Campaign or falls back to this queue.
            $table->foreignId('default_voice_queue_id')
                ->nullable()
                ->after('voice_gateway_id')
                ->constrained('omni_queues')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voice_tenant_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_voice_queue_id');
        });
    }
};
