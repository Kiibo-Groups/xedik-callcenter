<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'is_active', 'order'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }

    public function leads()
    {
        return $this->hasMany(Lead::class, 'source', 'name');
    }
}
