<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Models\OmniQueue;
use Illuminate\Http\Request;

class EmailAccountController extends Controller
{
    public function index()
    {
        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $accounts = EmailAccount::with('omniQueue', 'tenant')
                                ->orderBy('name')
                                ->paginate(20);

        return view('email_accounts.index', compact('accounts', 'isSuperAdmin'));
    }

    public function create()
    {
        $queues = OmniQueue::active()->whereIn('type', ['email', 'omni'])->orderBy('name')->get();

        return view('email_accounts.form', [
            'account' => new EmailAccount(),
            'queues'  => $queues,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        EmailAccount::create($data);

        return redirect()->route('email-accounts.index')
                         ->with('success', 'Email account created successfully.');
    }

    public function edit(EmailAccount $emailAccount)
    {
        $queues = OmniQueue::active()->whereIn('type', ['email', 'omni'])->orderBy('name')->get();

        return view('email_accounts.form', [
            'account' => $emailAccount,
            'queues'  => $queues,
        ]);
    }

    public function update(Request $request, EmailAccount $emailAccount)
    {
        $data = $this->validated($request, $emailAccount);

        $emailAccount->update($data);

        return redirect()->route('email-accounts.index')
                         ->with('success', 'Email account updated successfully.');
    }

    public function destroy(EmailAccount $emailAccount)
    {
        $emailAccount->delete();

        return redirect()->route('email-accounts.index')
                         ->with('success', 'Email account deleted.');
    }

    public function testConnection(Request $request)
    {
        $request->validate([
            'imap_host'     => 'required|string',
            'imap_port'     => 'required|integer',
            'imap_security' => 'required|in:SSL,TLS,NONE',
            'email'         => 'required|email',
        ]);

        // Si no mandan password, buscar la cuenta por email y usar la guardada
        $password = $request->password;
        if (empty($password)) {
            $account = EmailAccount::where('email', $request->email)->first();
            $password = $account?->password;
        }

        if (empty($password)) {
            return response()->json(['success' => false, 'message' => 'No password available. Please enter the password.']);
        }

        try {
            $cm = new \Webklex\PHPIMAP\ClientManager();

            $client = $cm->make([
                'host'          => $request->imap_host,
                'port'          => (int) $request->imap_port,
                'encryption'    => strtolower($request->imap_security) === 'none' ? false : strtolower($request->imap_security),
                'validate_cert' => false,
                'username'      => $request->email,
                'password'      => $password,
                'protocol'      => 'imap',
            ]);

            $client->connect();
            $client->disconnect();

            return response()->json(['success' => true, 'message' => 'Connection successful.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function validated(Request $request, ?EmailAccount $account = null): array
    {
        $rules = [
            'name'          => 'required|string|max:100',
            'from_name'     => 'nullable|string|max:255',
            'from_email'    => 'nullable|email|max:255',
            'reply_to'      => 'nullable|email|max:255',
            'email'         => 'required|email|max:150',
            'imap_host'     => 'required|string|max:150',
            'imap_port'     => 'required|integer|min:1|max:65535',
            'imap_security' => 'required|in:SSL,TLS,NONE',
            'smtp_host'     => 'required|string|max:150',
            'smtp_port'     => 'required|integer|min:1|max:65535',
            'smtp_security' => 'required|in:TLS,SSL,NONE',
            'smtp_username' => 'nullable|string|max:150',
            'smtp_password' => 'nullable|string|min:4',
            'require_auth'  => 'boolean',
            'omni_queue_id' => 'nullable|exists:omni_queues,id',
            'active'        => 'boolean',
        ];

        if ($account === null || $account->exists === false) {
            $rules['password'] = 'required|string|min:4';
        } else {
            $rules['password'] = 'nullable|string|min:4';
        }

        $data = $request->validate($rules);

        $data['require_auth'] = $request->boolean('require_auth');
        $data['active']       = $request->boolean('active');

        if (empty($data['password'])) {
            unset($data['password']);
        }

        if (empty($data['smtp_password'])) {
            unset($data['smtp_password']);
        }

        return $data;
    }
}