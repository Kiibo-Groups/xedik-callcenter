<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Campaign;
use App\Models\Script;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use BelongsToTenant;

    /** All valid channel values — validation enforces this; SQLite lacks enum DDL. */
    public const CHANNELS = ['email', 'chat', 'whatsapp', 'facebook', 'instagram', 'voice', 'sms'];

    protected $fillable = [
        'tenant_id', 'channel', 'email_account_id', 'whatsapp_account_id',
        'social_account_id', 'sms_channel_id', 'omni_queue_id', 'campaign_id', 'script_id',
        'dialer_campaign_id', 'lead_list_id', 'lead_list_row_id',
        'assigned_to', 'lead_id', 'subject',
        'from_email', 'from_name', 'external_id', 'status',
        'wait_since', 'assigned_at', 'resolved_at', 'disposition', 'read_at',
        'direction', 'transferred_from', 'notes',
        'ai_bot_active', 'resolved_source',
    ];

    protected $casts = [
        'wait_since'     => 'datetime',
        'assigned_at'    => 'datetime',
        'resolved_at'    => 'datetime',
        'ai_bot_active'  => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function campaign(): BelongsTo         { return $this->belongsTo(Campaign::class); }
    public function script(): BelongsTo           { return $this->belongsTo(Script::class); }
    public function dialerCampaign(): BelongsTo   { return $this->belongsTo(DialerCampaign::class); }
    public function leadList(): BelongsTo         { return $this->belongsTo(LeadList::class); }
    public function emailAccount(): BelongsTo    { return $this->belongsTo(EmailAccount::class); }
    public function whatsappAccount(): BelongsTo { return $this->belongsTo(WhatsappAccount::class); }
    public function socialAccount(): BelongsTo   { return $this->belongsTo(SocialAccount::class); }
    public function smsChannel(): BelongsTo      { return $this->belongsTo(SmsChannel::class); }
    public function omniQueue(): BelongsTo       { return $this->belongsTo(OmniQueue::class); }
    public function assignedAgent(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function lead(): BelongsTo            { return $this->belongsTo(Lead::class); }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)
                    ->withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                    ->orderBy('sent_at', 'desc');
    }

    public function crmFiles(): HasMany
    {
        return $this->hasMany(CrmFile::class);
    }

    /** Voice call records for this conversation. */
    public function voiceCalls(): HasMany
    {
        return $this->hasMany(VoiceCall::class);
    }

    /** Most recent voice call (eager-loadable). */
    public function latestVoiceCall(): HasOne
    {
        return $this->hasOne(VoiceCall::class)->ofMany([
            'created_at' => 'max',
            'id' => 'max',
        ]);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopePending($query)  { return $query->where('status', 'pending'); }
    public function scopeActive($query)   { return $query->where('status', 'active'); }
    public function scopeEmail($query)    { return $query->where('channel', 'email'); }
    public function scopeWhatsapp($query) { return $query->where('channel', 'whatsapp'); }
    public function scopeVoice($query)    { return $query->where('channel', 'voice'); }
    public function scopeSms($query)      { return $query->where('channel', 'sms'); }

    /** Conversations the assignment engine is allowed to touch (not AI-controlled). */
    public function scopeNotAiControlled($query)
    {
        return $query->where(function ($q) {
            $q->where('ai_bot_active', false)->orWhereNull('ai_bot_active');
        });
    }

    // ── Channel helpers ──────────────────────────────────────────────────────

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isActive(): bool   { return $this->status === 'active'; }
    public function isResolved(): bool { return $this->status === 'resolved'; }
    public function isEmail(): bool    { return $this->channel === 'email'; }
    public function isWhatsapp(): bool { return $this->channel === 'whatsapp'; }
    public function isSocial(): bool   { return in_array($this->channel, ['facebook', 'instagram'], true); }
    public function isVoice(): bool    { return $this->channel === 'voice'; }
    public function isSms(): bool      { return $this->channel === 'sms'; }
    public function isAiControlled(): bool { return (bool) $this->ai_bot_active; }

    // ── Voice-specific helpers ───────────────────────────────────────────────

    /**
     * For Voice conversations, from_email stores the normalized remote phone number.
     * This accessor provides a semantic alias without changing the DB column.
     */
    public function getRemotePhoneAttribute(): ?string
    {
        return $this->isVoice() ? $this->from_email : null;
    }

    /**
     * A Voice conversation is in "wrap-up" state when:
     *  - channel = voice
     *  - status = active (agent still owns it, not yet disposed)
     *  - disposition is null (not yet resolved)
     *  - the most-recent voice call is in a terminal state
     *
     * This is a computed helper — no new DB column needed.
     * Requires latestVoiceCall to be loaded.
     */
    public function isVoiceWrapUpPending(): bool
    {
        if (
            !$this->isVoice()
            || !$this->isActive()
            || $this->disposition !== null
            || $this->resolved_at !== null
        ) {
            return false;
        }

        $call = $this->relationLoaded('latestVoiceCall')
            ? $this->latestVoiceCall
            : $this->latestVoiceCall()->first();

        return $call !== null
            && $call->isTerminal()
            && $call->answered_at !== null;
    }
}
