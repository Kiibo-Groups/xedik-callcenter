<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Call extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'lead_id', 'agent_id', 'disposition', 'direction', 'called_at',
        'duration', 'notes', 'scheduled_at', 'is_scheduled', 'completed', 'recording_id', 'uniqueid', 'dialer_campaign',
    ];

    protected $casts = [
        'called_at'    => 'datetime',
        'scheduled_at' => 'datetime',
        'is_scheduled' => 'boolean',
        'completed'    => 'boolean',
        'duration'     => 'integer',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Label legible — usa el nombre real guardado del dialer
     * answer_machine → Answer Machine (por si hay datos viejos con slug)
     */
    public function getDispositionLabelAttribute(): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $this->disposition));
    }

    /**
     * Badge style — busca colores en la whitelist del dialer
     * Si no existe usa un color gris por default
     */
    public function getDispositionBadgeStyleAttribute(): string
    {
        static $cache = [];

        $key = strtolower(trim($this->disposition ?? ''));
        if ($key === '') return 'color:#374151;background:#f3f4f6;';

        if (!array_key_exists($key, $cache)) {
            $disp = DialerDisposition::whereRaw('LOWER(name) = ?', [$key])->first();
            $cache[$key] = $disp
                ? "color:{$disp->color};background:{$disp->bg_color};"
                : 'color:#374151;background:#f3f4f6;';
        }

        return $cache[$key];
    }

    /**
     * Clase CSS del badge (legacy — para compatibilidad con scheduled calls)
     */
    public function getDispositionBadgeAttribute(): string
    {
        return 'badge-secondary';
    }

    /**
     * Duración formateada mm:ss
     */
    public function getDurationFormattedAttribute(): string
    {
        $duration = (int) ($this->duration ?? 0);
        if ($duration <= 0) return '—';
        $mins = floor($duration / 60);
        $secs = $duration % 60;
        return sprintf('%02d:%02d', $mins, $secs);
    }
}
