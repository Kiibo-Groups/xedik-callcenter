<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'order_number',
        'channel',
        'customer_name',
        'customer_phone',
        'items_summary',
        'delivery_type',
        'delivery_address',
        'payment_method',
        'total_amount',
        'status',
        'notes',
        'call_id',
        'conversation_id',
        'lead_id',
        'preparing_at',
        'ready_at',
        'delivered_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'preparing_at' => 'datetime',
        'ready_at'     => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
