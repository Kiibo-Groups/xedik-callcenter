<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaign extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'campaign_id',
        'lead_list_id',
        'channel',
        'email_account_id',
        'sms_channel_id',
        'whatsapp_account_id',
        'email_template_id',
        'sms_template_id',
        'whatsapp_template_id',
        'custom_subject',
        'custom_body',
        'batch_size',
        'delay_seconds',
        'max_retries',
        'open_conversation',
        'status',
        'scheduled_at',
        'scheduled_end_at',
        'daily_start_time',
        'daily_end_time',
        'started_at',
        'completed_at',
        'total_queued',
        'sent_count',
        'failed_count',
        'skipped_count',
    ];

    protected $casts = [
        'batch_size'        => 'integer',
        'delay_seconds'     => 'integer',
        'max_retries'       => 'integer',
        'open_conversation' => 'boolean',
        'total_queued'      => 'integer',
        'sent_count'        => 'integer',
        'failed_count'      => 'integer',
        'skipped_count'     => 'integer',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'scheduled_at'      => 'datetime',
        'scheduled_end_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScopes();
    }

    public function leadList(): BelongsTo
    {
        return $this->belongsTo(LeadList::class, 'lead_list_id')->withoutGlobalScopes();
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id')->withoutGlobalScopes();
    }

    public function smsChannel(): BelongsTo
    {
        return $this->belongsTo(SmsChannel::class, 'sms_channel_id')->withoutGlobalScopes();
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id')->withoutGlobalScopes();
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id')->withoutGlobalScopes();
    }

    public function smsTemplate(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id')->withoutGlobalScopes();
    }

    public function whatsappTemplate(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id')->withoutGlobalScopes();
    }

    public function isRunning(): bool   { return $this->status === 'running'; }
    public function isIdle(): bool      { return $this->status === 'idle'; }
    public function isPaused(): bool    { return $this->status === 'paused'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isScheduled(): bool { return $this->status === 'scheduled'; }

    public function senderLabel(): string
    {
        return match ($this->channel) {
            'email'    => $this->emailAccount?->email ?? $this->emailAccount?->name ?? '—',
            'sms'      => $this->smsChannel?->address ?? $this->smsChannel?->display_name ?? '—',
            'whatsapp' => $this->whatsappAccount?->phone_number ?? $this->whatsappAccount?->display_name ?? '—',
            default    => '—',
        };
    }

    public function templateLabel(): string
    {
        return match ($this->channel) {
            'email'    => $this->emailTemplate?->name ?? ($this->custom_subject ? 'Custom Email' : '—'),
            'sms'      => $this->smsTemplate?->name ?? ($this->custom_body ? 'Custom SMS' : '—'),
            'whatsapp' => $this->whatsappTemplate?->display_name ?? $this->whatsappTemplate?->name ?? '—',
            default    => '—',
        };
    }
}

