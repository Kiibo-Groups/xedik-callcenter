<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preview Dialer operational identity: the OmniQueue whose Voice settings supply
     * asterisk_queue_name for QueueLog / telephony_calls / reporting. It does NOT
     * distribute calls — Preview still offers a lead directly to the agent. Nullable
     * at the DB level (existing rows and non-preview dialer types keep NULL); the
     * "required for preview" rule is enforced in the controller validation.
     */
    public function up(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table): void {
            $table->foreignId('omni_queue_id')
                ->nullable()
                ->after('campaign_id')
                ->constrained('omni_queues')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('omni_queue_id');
        });
    }
};
