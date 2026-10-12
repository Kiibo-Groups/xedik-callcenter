<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallingStateRule extends Model
{
    protected $fillable = [
        'country_code', 'state_code', 'state_name', 'rule_type',
        'start_time', 'end_time', 'days_of_week', 'is_active',
        'source', 'source_url', 'source_updated_at', 'notes',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'days_of_week'      => 'array',
        'source_updated_at' => 'date',
    ];
}
