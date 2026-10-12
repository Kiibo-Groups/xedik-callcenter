<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'first_name', 'last_name', 'email', 'phone', 'phone_2',
        'address', 'city', 'state', 'zip', 'country',
        'status', 'notes', 'source', 'value', 'assigned_to', 'campaign_id',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function calls()
    {
        return $this->hasMany(Call::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'lead_tag')
            ->withTimestamps()
            ->orderByPivot('created_at', 'asc');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /** Relationship to status catalog */
    public function statusModel()
    {
        return $this->belongsTo(LeadStatus::class, 'status', 'slug');
    }

    /** Inline badge style driven by the catalog colors */
    public function getStatusBadgeStyleAttribute(): string
    {
        static $cache = [];
        $key = ($this->tenant_id ?? 0) . ':' . $this->status;
        if (!isset($cache[$key])) {
            $s = LeadStatus::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->where('tenant_id', $this->tenant_id)
                ->where('slug', $this->status)
                ->first();
            $cache[$key] = $s
                ? "background:{$s->bg_color};color:{$s->color};"
                : 'background:#f1f5f9;color:#64748b;';
        }
        return $cache[$key];
    }

    /** Human-readable label from catalog */
    public function getStatusLabelAttribute(): string
    {
        static $cache = [];
        $key = ($this->tenant_id ?? 0) . ':' . $this->status;
        if (!isset($cache[$key])) {
            $cache[$key] = LeadStatus::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->where('tenant_id', $this->tenant_id)
                ->where('slug', $this->status)
                ->value('name')
                ?? ucwords(str_replace('_', ' ', $this->status));
        }
        return $cache[$key];
    }

    /** Kept for backward compat */
    public function getStatusBadgeAttribute(): string
    {
        return 'badge-secondary';
    }

    public function lastCall()
    {
        return $this->hasOne(Call::class)
            ->where('is_scheduled', false)
            ->orderBy('called_at', 'desc')
            ->orderBy('id', 'desc');
    }

    public function scheduledCalls()
    {
        return $this->hasMany(Call::class)->where('is_scheduled', true)->where('completed', false);
    }
    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

}
