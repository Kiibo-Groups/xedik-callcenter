<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'subject', 'body', 'variable_source', 'leadlist_id', 'created_by',
    ];

    protected $casts = [
        'leadlist_id' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leadList(): BelongsTo
    {
        return $this->belongsTo(LeadList::class, 'leadlist_id');
    }
}
