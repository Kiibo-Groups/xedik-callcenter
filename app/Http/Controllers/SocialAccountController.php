<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OmniQueue;
use App\Models\SocialAccount;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_social_accounts'), 403);

        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $accounts     = SocialAccount::with('omniQueue', 'tenant')->orderBy('display_name')->get();
        $queues       = OmniQueue::active()->whereIn('type', ['chat', 'omni'])->orderBy('name')->get();

        return view('social_accounts.index', compact('accounts', 'queues', 'isSuperAdmin'));
    }

    public function update(Request $request, SocialAccount $socialAccount)
    {
        abort_unless(auth()->user()->hasPermission('manage_social_accounts'), 403);

        $data = $request->validate([
            'display_name'  => 'required|string|max:100',
            'omni_queue_id' => 'nullable|exists:omni_queues,id',
        ]);

        $socialAccount->update($data);

        return redirect()->route('social-accounts.index')
                         ->with('success', "Account \"{$socialAccount->display_name}\" updated.");
    }

    public function toggleActive(SocialAccount $socialAccount)
    {
        abort_unless(auth()->user()->hasPermission('manage_social_accounts'), 403);

        $socialAccount->update(['is_active' => !$socialAccount->is_active]);

        return response()->json(['success' => true, 'is_active' => $socialAccount->is_active]);
    }

    public function destroy(SocialAccount $socialAccount)
    {
        abort_unless(auth()->user()->hasPermission('manage_social_accounts'), 403);

        if ($socialAccount->conversations()->exists()) {
            return back()->with('error', "Cannot delete account \"{$socialAccount->display_name}\" — it has conversations linked to it.");
        }

        $name = $socialAccount->display_name;
        $socialAccount->delete();

        return redirect()->route('social-accounts.index')
                         ->with('success', "Account \"{$name}\" disconnected.");
    }
}
