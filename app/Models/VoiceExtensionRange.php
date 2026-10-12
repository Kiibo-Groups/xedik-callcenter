<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceExtensionRange extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'range_start',
        'range_end',
        'label',
        'is_active',
    ];

    protected $casts = [
        'range_start' => 'integer',
        'range_end'   => 'integer',
        'is_active'   => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // Method call: $range->capacity()
    public function capacity(): int
    {
        return max(0, $this->range_end - $this->range_start + 1);
    }

    // Property access: $range->capacity — old-style accessor delegates to the method
    // so both calling conventions work without conflict.
    public function getCapacityAttribute(): int
    {
        return $this->capacity();
    }

    /**
     * Extensions actively assigned to a live user in this range.
     * (user_id IS NOT NULL — normal active/pending/failed provisioning states)
     */
    public function assignedCount(): int
    {
        return VoiceExtension::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->whereBetween('extension', [$this->range_start, $this->range_end])
            ->whereNotNull('user_id')
            ->count();
    }

    /**
     * Extensions that have been released from their user but whose numbers
     * are still "occupied" (released or deprovision_failed).
     * These block auto-assignment until a Superadmin runs Free Extension.
     */
    public function pendingCleanupCount(): int
    {
        return VoiceExtension::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->whereBetween('extension', [$this->range_start, $this->range_end])
            ->whereNull('user_id')
            ->whereIn('sip_provisioning_status', ['released', 'deprovision_failed'])
            ->count();
    }

    /**
     * Numbers in this range not occupied by any voice_extensions record.
     * pending_cleanup numbers block auto-assignment until explicitly freed.
     */
    public function availableCount(): int
    {
        $occupied = VoiceExtension::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->whereBetween('extension', [$this->range_start, $this->range_end])
            ->count();

        return max(0, $this->capacity() - $occupied);
    }

    public function overlapsWithinTenant(int $start, int $end, ?int $excludeId = null): bool
    {
        return self::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->where('is_active', true)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->where('range_start', '<=', $end)
            ->where('range_end', '>=', $start)
            ->exists();
    }
}
