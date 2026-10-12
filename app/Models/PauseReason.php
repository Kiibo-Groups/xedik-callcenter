<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PauseReason extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name', 'max_minutes', 'color', 'is_active', 'sort_order'
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'max_minutes' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}