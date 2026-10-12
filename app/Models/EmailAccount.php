<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'from_name',
        'from_email',
        'reply_to',
        'email',
        'password',
        'imap_host',
        'imap_port',
        'imap_security',
        'smtp_host',
        'smtp_port',
        'smtp_security',
        'smtp_username',
        'smtp_password',
        'require_auth',
        'omni_queue_id',
        'active',
    ];

    protected $casts = [
        'password'        => 'encrypted',
        'smtp_password'   => 'encrypted',
        'require_auth'    => 'boolean',
        'active'          => 'boolean',
        'imap_port'       => 'integer',
        'smtp_port'       => 'integer',
        'last_checked_at' => 'datetime',
    ];

    protected $hidden = ['password', 'smtp_password'];

    public function omniQueue(): BelongsTo
    {
        return $this->belongsTo(OmniQueue::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function imapConfig(): array
    {
        return [
            'host'       => $this->imap_host,
            'port'       => $this->imap_port,
            'encryption' => strtolower($this->imap_security),
            'username'   => $this->email,
            'password'   => $this->password,
            'protocol'   => 'imap',
        ];
    }

    public function smtpConfig(): array
    {
        return [
            'transport'  => 'smtp',
            'host'       => $this->smtp_host,
            'port'       => $this->smtp_port,
            'encryption' => strtolower($this->smtp_security) === 'none' ? null : strtolower($this->smtp_security),
            'username'   => $this->smtp_username ?: $this->email,
            'password'   => $this->smtp_password ?: $this->password,
        ];
    }
}