<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndpointCatalog extends Model
{
    protected $table = 'endpoint_catalog';

    protected $fillable = [
        'method', 'group', 'name', 'description', 'url',
        'auth_description', 'params', 'response_example',
        'example', 'sort_order', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function getParsedParamsAttribute(): array
    {
        if (empty($this->params)) return [];
        $decoded = json_decode($this->params, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function methodColor(): string
    {
        return match (strtoupper($this->method)) {
            'GET'    => '#2563eb',
            'POST'   => '#16a34a',
            'PATCH',
            'PUT'    => '#d97706',
            'DELETE' => '#dc2626',
            default  => '#6b7280',
        };
    }

    public function methodBg(): string
    {
        return match (strtoupper($this->method)) {
            'GET'    => '#dbeafe',
            'POST'   => '#dcfce7',
            'PATCH',
            'PUT'    => '#fef3c7',
            'DELETE' => '#fee2e2',
            default  => '#f3f4f6',
        };
    }
}
