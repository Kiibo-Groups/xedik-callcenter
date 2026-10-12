<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TelephonyQueueLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'telephony_queue_log';

    protected $fillable = [
        'pbx_id',
        'tenant_id',
        'asterisk_queue_log_id',
        'event_time',
        'linkedid',
        'queuename',
        'agent',
        'event',
        'data1',
        'data2',
        'data3',
        'data4',
        'data5',
    ];

    protected $casts = [
        'event_time' => 'datetime',
    ];
}
