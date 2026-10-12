<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DialerDnc extends Model
{
    use BelongsToTenant;

    protected $table = 'dialer_dnc';

    protected $fillable = ['tenant_id', 'phone', 'reason', 'added_by'];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
