<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConversationAutomationChannel;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConversationAutomationController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()->hasPermission('manage_conversation_automation'), 403);

        $tenantId = auth()->user()->tenant_id;

        $rows = ConversationAutomationChannel::where('tenant_id', $tenantId)
            ->get()
            ->keyBy('channel');

        $channels = collect(ConversationAutomationChannel::SUPPORTED_CHANNELS)->map(function (string $channel) use ($rows) {
            $row = $rows->get($channel);
            return [
                'channel'     => $channel,
                'enabled'     => (bool) ($row->enabled ?? false),
                'webhook_url' => $row->webhook_url ?? null,
                'auth_type'   => $row->auth_type ?? 'none',
                'has_api_key' => (bool) ($row && $row->api_key),
                'timeout_ms'  => $row->timeout_ms ?? 5000,
                'updated_at'  => $row?->updated_at,
            ];
        });

        return view('conversation_automation.index', compact('channels'));
    }

    public function update(Request $request, string $channel): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_conversation_automation'), 403);
        abort_unless(in_array($channel, ConversationAutomationChannel::SUPPORTED_CHANNELS, true), 404);

        $data = $request->validate([
            'enabled'     => 'boolean',
            'webhook_url' => 'nullable|url|max:500',
            'auth_type'   => 'required|in:none,api_key',
            'api_key'     => 'nullable|string|max:255',
            'timeout_ms'  => 'nullable|integer|min:1000|max:30000',
        ]);

        $tenantId = auth()->user()->tenant_id;

        $row = ConversationAutomationChannel::firstOrNew([
            'tenant_id' => $tenantId,
            'channel'   => $channel,
        ]);

        $row->enabled     = $request->boolean('enabled');
        $row->webhook_url = $data['webhook_url'] ?? null;
        $row->events      = ['inbound_message'];
        $row->auth_type   = $data['auth_type'];
        $row->timeout_ms  = $data['timeout_ms'] ?? 5000;

        // Leave-blank-to-keep pattern — same as WhatsApp Accounts' encrypted fields.
        if (!empty($data['api_key'])) {
            $row->api_key = $data['api_key'];
        }

        $row->save();

        return redirect()->route('admin.conversation-automation.index')
            ->with('success', 'Automation settings for "' . ucfirst($channel) . '" updated.');
    }
}
