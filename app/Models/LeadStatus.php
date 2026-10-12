<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeadStatus extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'color', 'bg_color',
        'description', 'order', 'is_default', 'is_active',
        'show_in_pipeline', 'is_won', 'is_lost',
    ];

    protected $casts = [
        'is_default'       => 'boolean',
        'is_active'        => 'boolean',
        'show_in_pipeline' => 'boolean',
        'is_won'           => 'boolean',
        'is_lost'          => 'boolean',
    ];

    /**
     * Only active statuses, ordered
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }

    /**
     * Leads using this status
     */
    public function leads()
    {
        return $this->hasMany(Lead::class, 'status', 'slug');
    }

    /**
     * Badge HTML inline style
     */
    public function getBadgeStyleAttribute(): string
    {
        return "background:{$this->bg_color};color:{$this->color};";
    }

    /**
     * Auto-generate slug from name
     */
    public static function makeSlug(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));
    }

    /**
     * Get default status slug.
     * Accepts optional $tenantId for webhook / non-auth contexts.
     */
    public static function getDefault(?int $tenantId = null): string
    {
        $query = static::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->where('is_default', true);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->value('slug') ?? 'new';
    }
}
