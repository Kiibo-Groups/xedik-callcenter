<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DialerDnc;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DialerDncController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_dnc'), 403);

        $dncs = DialerDnc::with('addedBy:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        return view('dialer_dnc.index', compact('dncs'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_dnc'), 403);

        $tenantId = auth()->user()->tenant_id;

        $request->validate([
            'phone'  => [
                'required', 'string', 'max:20',
                Rule::unique('dialer_dnc', 'phone')->where('tenant_id', $tenantId),
            ],
            'reason' => 'nullable|string|max:255',
        ]);

        $phone = preg_replace('/\D/', '', $request->phone);

        DialerDnc::create([
            'phone'    => $phone,
            'reason'   => $request->reason,
            'added_by' => auth()->id(),
        ]);

        return back()->with('success', "Number {$phone} added to DNC.");
    }

    public function destroy(DialerDnc $dialerDnc)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_dnc'), 403);

        $phone = $dialerDnc->phone;
        $dialerDnc->delete();

        return back()->with('success', "Number {$phone} removed from DNC.");
    }
}
