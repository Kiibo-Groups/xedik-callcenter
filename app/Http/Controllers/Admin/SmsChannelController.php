<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\SmsChannel;
use App\Models\SmsProviderAccount;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * El número/canal SMS — recurso puramente TÉCNICO que provisiona el superadmin:
 * proveedor, número/Sender ID, tenant asignado, país, capacidades, estado.
 * El superadmin NUNCA decide la queue operativa aquí — eso es responsabilidad
 * exclusiva del tenant-admin (o del superadmin actuando "as tenant") en
 * \App\Http\Controllers\SmsChannelController. omni_queue_id no se acepta ni
 * se modifica desde este controller bajo ninguna circunstancia.
 */
class SmsChannelController extends Controller
{
    public function index()
    {
        $this->authorizeSuperAdmin();

        $channels = SmsChannel::with('tenant', 'providerAccount', 'omniQueue')->orderBy('tenant_id')->orderBy('display_name')->get();
        $tenants  = Tenant::withoutGlobalScopes()->orderBy('name')->get();
        $accounts = SmsProviderAccount::where('is_active', true)->orderBy('display_name')->get();

        return view('admin.sms.channels.index', compact('channels', 'tenants', 'accounts'));
    }

    public function store(Request $request)
    {
        $this->authorizeSuperAdmin();

        $data = $this->validated($request);
        $normalized = PhoneHelper::normalizeForSearch($data['address']);

        if ($this->normalizedAddressExists($normalized)) {
            return back()->with('error', 'This number is already registered as an SMS channel.')->withInput();
        }

        SmsChannel::create([
            'tenant_id'               => $data['tenant_id'],
            'sms_provider_account_id' => $data['sms_provider_account_id'],
            'omni_queue_id'           => null, // decisión operativa del tenant-admin, nunca del superadmin
            'display_name'            => $data['display_name'],
            'address'                 => $data['address'],
            'normalized_address'      => $normalized,
            'address_type'            => $data['address_type'],
            'country'                 => $data['country'] ?? null,
            'capabilities'            => ['p2p' => $request->boolean('supports_p2p', true), 'a2p' => $request->boolean('supports_a2p', false)],
            'is_active'               => true,
        ]);

        return back()->with('success', "SMS channel \"{$data['display_name']}\" created.");
    }

    public function update(Request $request, SmsChannel $smsChannel)
    {
        $this->authorizeSuperAdmin();

        $data = $this->validated($request, $smsChannel);
        $normalized = PhoneHelper::normalizeForSearch($data['address']);

        if ($this->normalizedAddressExists($normalized, $smsChannel->id)) {
            return back()->with('error', 'This number is already registered as an SMS channel.')->withInput();
        }

        // omni_queue_id deliberadamente ausente de este update — este controller
        // nunca lo toca, sea cual sea su valor actual (lo administra el tenant-admin).
        $smsChannel->update([
            'tenant_id'               => $data['tenant_id'],
            'sms_provider_account_id' => $data['sms_provider_account_id'],
            'display_name'            => $data['display_name'],
            'address'                 => $data['address'],
            'normalized_address'      => $normalized,
            'address_type'            => $data['address_type'],
            'country'                 => $data['country'] ?? null,
            'capabilities'            => ['p2p' => $request->boolean('supports_p2p', true), 'a2p' => $request->boolean('supports_a2p', false)],
        ]);

        return back()->with('success', "SMS channel \"{$smsChannel->display_name}\" updated.");
    }

    private function normalizedAddressExists(string $normalized, ?int $exceptId = null): bool
    {
        return SmsChannel::withoutGlobalScopes()
            ->where('normalized_address', $normalized)
            ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    public function destroy(SmsChannel $smsChannel)
    {
        $this->authorizeSuperAdmin();

        if ($smsChannel->conversations()->exists()) {
            return back()->with('error', "Cannot delete \"{$smsChannel->display_name}\" — it has conversations linked to it.");
        }

        $name = $smsChannel->display_name;
        $smsChannel->delete();

        return back()->with('success', "SMS channel \"{$name}\" deleted.");
    }

    public function toggleActive(SmsChannel $smsChannel)
    {
        $this->authorizeSuperAdmin();

        $smsChannel->update(['is_active' => !$smsChannel->is_active]);

        return response()->json(['is_active' => $smsChannel->is_active]);
    }

    private function validated(Request $request, ?SmsChannel $channel = null): array
    {
        // omni_queue_id intencionalmente NO está en esta lista de reglas — Request::validate()
        // descarta cualquier campo no declarado aquí, así que un POST/PUT malicioso que
        // incluya omni_queue_id nunca llega a $data ni a store()/update().
        $data = $request->validate([
            'tenant_id'               => 'required|integer|exists:tenants,id',
            'sms_provider_account_id' => 'required|integer|exists:sms_provider_accounts,id',
            'display_name'            => 'required|string|max:120',
            'address'                 => 'required|string|max:30',
            'address_type' => 'required|in:long_code,toll_free,short_code',
            'country'      => 'nullable|string|max:5',
        ]);

        return $data;
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
