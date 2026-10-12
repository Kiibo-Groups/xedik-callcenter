<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'color', 'bg_color', 'created_by',
    ];

    /**
     * Leads tagged with this tag
     */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_tag');
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
}
