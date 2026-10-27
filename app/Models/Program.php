<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Program extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'slug', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->slug = Str::slug($m->name));
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function scripts()
    {
        return $this->hasManyThrough(Script::class, Campaign::class);
    }
}
