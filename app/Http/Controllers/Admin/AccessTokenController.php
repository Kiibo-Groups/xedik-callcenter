<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccessTokenController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()->hasPermission('manage_access_tokens'), 403);

        $user    = auth()->user();
        $tenants = $user->isSuperAdmin() ? Tenant::orderBy('name')->get() : collect();

        $tokens = AccessToken::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('tenant_id', $user->tenant_id))
            ->with('tenant')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('access_tokens.index', compact('tokens', 'tenants'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_access_tokens'), 403);

        $user = auth()->user();

        $rules = ['label' => 'required|string|max:100'];
        if ($user->isSuperAdmin()) {
            $rules['tenant_id'] = 'required|integer|exists:tenants,id';
        }
        $request->validate($rules);

        $tenantId = $user->isSuperAdmin()
            ? (int) $request->tenant_id
            : $user->tenant_id;

        AccessToken::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)->create([
            'tenant_id' => $tenantId,
            'label'     => $request->label,
            'token'     => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        return back()->with('success', "Token \"{$request->label}\" generated.");
    }

    public function revoke(AccessToken $accessToken): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_access_tokens'), 403);

        $accessToken->update(['is_active' => false]);

        return back()->with('success', "Token \"{$accessToken->label}\" revoked.");
    }

    public function destroy(AccessToken $accessToken): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_access_tokens'), 403);

        $label = $accessToken->label;
        $accessToken->delete();

        return back()->with('success', "Token \"{$label}\" deleted.");
    }
}
