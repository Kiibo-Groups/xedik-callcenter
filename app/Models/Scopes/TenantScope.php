<?php
declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if (!$user) return;
        if ($user->is_superadmin) return;
        $builder->where($model->getTable() . '.tenant_id', $user->tenant_id);
    }
}
