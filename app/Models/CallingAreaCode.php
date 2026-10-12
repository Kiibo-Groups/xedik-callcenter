<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallingAreaCode extends Model
{
    protected $fillable = [
        'area_code', 'country_code', 'state', 'state_code',
        'time_zone', 'time_zone_abbr', 'status', 'is_active',
        'source', 'source_updated_at', 'notes',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'source_updated_at' => 'date',
    ];
}
