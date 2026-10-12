<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsProviderAccount;
use App\Models\Tenant;
use App\Services\Sms\SmsProviderManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Conexión técnica con un proveedor SMS (credenciales, URL de envío). Superadmin-only.
 * tenant_id nullable: una cuenta puede alimentar sms_channels de varios tenants
 * (patrón VoiceGateway) o quedar dedicada a uno solo.
 */
class SmsProviderAccountController extends Controller
{
    public function __construct(private readonly SmsProviderManager $providerManager) {}

    public function index()
    {
        $this->authorizeSuperAdmin();

        $accounts  = SmsProviderAccount::with('tenant')->orderByRaw('tenant_id IS NULL DESC')->orderBy('display_name')->get();
        $tenants   = Tenant::withoutGlobalScopes()->orderBy('name')->get();
        $providers = $this->providerManager->availableProviders();

        return view('admin.sms.provider_accounts.index', compact('accounts', 'tenants', 'providers'));
    }

    public function store(Request $request)
    {
        $this->authorizeSuperAdmin();

        $data = $this->validated($request);

        SmsProviderAccount::create([
            'tenant_id'    => $data['tenant_id'],
            'provider'     => $data['provider'],
            'display_name' => $data['display_name'],
            'region'       => $data['region'] ?? null,
            'credentials'  => ['username' => $data['username'], 'password' => $data['password']],
            'settings'     => ['outbound_url' => $data['outbound_url']],
            'is_active'    => true,
        ]);

        return back()->with('success', "Provider account \"{$data['display_name']}\" created.");
    }

    public function update(Request $request, SmsProviderAccount $smsProviderAccount)
    {
        $this->authorizeSuperAdmin();

        $data = $this->validated($request, $smsProviderAccount);

        $credentials = $smsProviderAccount->credentials ?? [];
        if (!empty($data['username'])) {
            $credentials['username'] = $data['username'];
        }
        if (!empty($data['password'])) {
            $credentials['password'] = $data['password'];
        }

        $smsProviderAccount->update([
            'tenant_id'    => $data['tenant_id'],
            'provider'     => $data['provider'],
            'display_name' => $data['display_name'],
            'region'       => $data['region'] ?? null,
            'credentials'  => $credentials,
            'settings'     => ['outbound_url' => $data['outbound_url']],
        ]);

        return back()->with('success', "Provider account \"{$smsProviderAccount->display_name}\" updated.");
    }

    public function destroy(SmsProviderAccount $smsProviderAccount)
    {
        $this->authorizeSuperAdmin();

        if ($smsProviderAccount->channels()->exists()) {
            return back()->with('error', "Cannot delete \"{$smsProviderAccount->display_name}\" — it has SMS channels linked to it.");
        }

        $name = $smsProviderAccount->display_name;
        $smsProviderAccount->delete();

        return back()->with('success', "Provider account \"{$name}\" deleted.");
    }

    public function toggleActive(SmsProviderAccount $smsProviderAccount)
    {
        $this->authorizeSuperAdmin();

        $smsProviderAccount->update(['is_active' => !$smsProviderAccount->is_active]);

        return response()->json(['is_active' => $smsProviderAccount->is_active]);
    }

    private function validated(Request $request, ?SmsProviderAccount $account = null): array
    {
        $rules = [
            'tenant_id'    => 'nullable|integer|exists:tenants,id',
            'provider'     => ['required', 'string', 'max:40', Rule::in(array_keys($this->providerManager->availableProviders()))],
            'display_name' => 'required|string|max:120',
            'region'       => 'nullable|string|max:60',
            'outbound_url' => 'required|url|max:255',
            'username'     => ($account === null || !$account->exists) ? 'required|string|max:150' : 'nullable|string|max:150',
            'password'     => ($account === null || !$account->exists) ? 'required|string|max:255' : 'nullable|string|max:255',
        ];

        $data = $request->validate($rules);
        $data['tenant_id'] = $data['tenant_id'] ?? null;

        return $data;
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
