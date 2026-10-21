<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OmniQueue;
use App\Models\WhatsappAccount;
use Illuminate\Http\Request;

class WhatsappAccountController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_accounts'), 403);
        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $accounts     = WhatsappAccount::with('omniQueue', 'tenant')->orderBy('display_name')->get();

        return view('whatsapp_accounts.index', compact('accounts', 'isSuperAdmin'));
    }

    public function create()
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_accounts'), 403);
        $queues = OmniQueue::active()->whereIn('type', ['chat', 'omni'])->orderBy('name')->get();

        return view('whatsapp_accounts.form', [
            'queues' => $queues,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_accounts'), 403);
        $data = $this->validated($request);
        $data['tenant_id'] = auth()->user()->tenant_id ?? session('tenant_id') ?? 2;

        WhatsappAccount::create($data);

        return redirect()->route('whatsapp-accounts.index')
                         ->with('success', "Account \"{$data['display_name']}\" created.");
    }

    public function edit(WhatsappAccount $whatsappAccount)
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_accounts'), 403);
        $queues = OmniQueue::active()->whereIn('type', ['chat', 'omni'])->orderBy('name')->get();

        return view('whatsapp_accounts.form', [
            'account' => $whatsappAccount,
            'queues'  => $queues,
        ]);
    }

    public function update(Request $request, WhatsappAccount $whatsappAccount)
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_accounts'), 403);
        $data = $this->validated($request, $whatsappAccount);

        $whatsappAccount->update($data);

        return redirect()->route('whatsapp-accounts.index')
                         ->with('success', "Account \"{$whatsappAccount->display_name}\" updated.");
    }

    public function destroy(WhatsappAccount $whatsappAccount)
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_accounts'), 403);

        if ($whatsappAccount->conversations()->exists()) {
            return back()->with('error', "Cannot delete account \"{$whatsappAccount->display_name}\" — it has conversations linked to it.");
        }

        $name = $whatsappAccount->display_name;
        $whatsappAccount->delete();

        return redirect()->route('whatsapp-accounts.index')
                         ->with('success', "Account \"{$name}\" deleted.");
    }

    private function validated(Request $request, ?WhatsappAccount $account = null): array
    {
        $rules = [
            'display_name'    => 'required|string|max:100',
            'phone_number'    => 'required|string|max:30',
            'phone_number_id' => 'required|string|max:50|unique:whatsapp_accounts,phone_number_id' .
                                 ($account?->exists ? ',' . $account->id : ''),
            'waba_id'         => 'required|string|max:50',
            'verify_token'    => 'required|string|max:100',
            'omni_queue_id'   => 'nullable|exists:omni_queues,id',
            'is_active'       => 'boolean',
            // app_secret required on create — webhook rejects payloads without it
            'app_secret' => ($account === null || !$account->exists)
                ? 'required|string|max:100'
                : 'nullable|string|max:100',
        ];

        // access_token required only on create
        if ($account === null || ! $account->exists) {
            $rules['access_token'] = 'required|string|min:10';
        } else {
            $rules['access_token'] = 'nullable|string|min:10';
        }

        $data = $request->validate($rules);
        $data['is_active'] = $request->boolean('is_active');

        // Don't overwrite encrypted fields if left blank on edit
        if (empty($data['access_token'])) {
            unset($data['access_token']);
        }
        if (empty($data['app_secret'])) {
            unset($data['app_secret']);
        }

        return $data;
    }
}
