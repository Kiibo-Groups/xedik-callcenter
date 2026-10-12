<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadList extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'table_name', 'log_table_name',
        'total_leads', 'status',
    ];

    public function dialerCampaigns(): HasMany
    {
        return $this->hasMany(DialerCampaign::class, 'lead_list_id');
    }

    public function hasActiveCampaigns(): bool
    {
        return $this->dialerCampaigns()
            ->whereIn('status', ['running', 'paused'])
            ->exists();
    }
}
