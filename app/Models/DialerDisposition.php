<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DialerDisposition extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color', 'bg_color', 'is_active', 'description', 'requires_callback_at'];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_callback_at' => 'boolean',
    ];

    /**
     * Buscar por nombre exacto (case-insensitive).
     * Acepta $tenantId para contextos sin auth (webhook).
     */
    public static function findByName(string $name, ?int $tenantId = null): ?self
    {
        $query = static::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))]);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->first();
    }

    /**
     * Inline style para el badge en el historial de llamadas
     */
    public function getBadgeStyleAttribute(): string
    {
        return "color:{$this->color};background:{$this->bg_color};";
    }
}
