<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Technical record of a single SIP call attempt within a Voice Conversation.
     *
     * One Conversation may eventually have multiple VoiceCall rows (redials, call-backs),
     * but in Phase 1 there is exactly one per Conversation.
     *
     * call_uuid: set by the frontend from its voice-local-{uuid} interaction ID (stripped prefix).
     * UNIQUE(tenant_id, call_uuid) enforces idempotency without cross-tenant collision.
     *
     * voice_session_id is nullable: it is populated when the backend can correlate the
     * JsSIP session via session_uuid (sent by the frontend). It is NOT required for
     * the Conversation to be created.
     *
     * Timestamps (ringing_at, answered_at, ended_at) are set by the backend on state
     * transitions, optionally aided by browser timestamps as auxiliary hints.
     * Backend is authoritative; browser timestamps are never blindly trusted.
     */
    public function up(): void
    {
        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            // Nullable: populated when frontend provides session_uuid and backend
            // can look it up in voice_sessions. Not required for call creation.
            $table->foreignId('voice_session_id')
                ->nullable()
                ->constrained('voice_sessions')
                ->nullOnDelete();

            // Idempotency key — UUID part of the frontend's voice-local-{uuid}.
            // Combined with tenant_id to prevent cross-tenant collision.
            $table->string('call_uuid', 36);

            $table->string('direction', 10);             // inbound | outbound

            // Raw values as received from the SIP stack — no normalization applied here.
            // Normalized versions live in Conversation.from_email (remote phone, normalized).
            $table->string('from_number', 40)->nullable(); // SIP From URI user part
            $table->string('to_number', 40)->nullable();   // SIP To URI user part

            // Normalized remote party number (e.g. "6621234567" for MX).
            // Populated by PhoneHelper::normalizeForSearch() on the backend.
            $table->string('remote_number', 40)->nullable();
            $table->string('remote_display_name', 150)->nullable();

            // State machine: ringing → connecting → active → held → ended | rejected | failed | missed
            // Terminal states: ended, rejected, failed, missed — no transition back to active.
            $table->string('call_state', 20)->default('ringing');

            $table->timestamp('ringing_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // Calculated by backend from (ended_at - ringing_at).
            $table->unsignedInteger('duration_seconds')->nullable();
            // Calculated by backend from (ended_at - answered_at).
            $table->unsignedInteger('talk_seconds')->nullable();

            $table->string('hangup_cause', 50)->nullable();

            $table->timestamps();

            // Idempotency: one call per tenant per UUID
            $table->unique(['tenant_id', 'call_uuid'], 'vc_tenant_uuid_unique');

            $table->index('conversation_id');
            $table->index('voice_session_id');
            $table->index(['tenant_id', 'call_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_calls');
    }
};
