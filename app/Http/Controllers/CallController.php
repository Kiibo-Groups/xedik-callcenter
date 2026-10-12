<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CallController extends Controller
{
    /**
     * Scheduled calls for current agent
     */
    public function schedule(Request $request)
    {
        $user = Auth::user();

        $query = Call::with(['lead', 'agent'])
            ->where('is_scheduled', true)
            ->where('completed', false);

        // Agents only see their own scheduled calls
        if (!$user->isAdmin()) {
            $query->where('agent_id', $user->id);
        }

        // Filter by agent (admin)
        if ($user->isAdmin() && $agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        // Filter by date
        if ($date = $request->get('date')) {
            $query->whereDate('scheduled_at', $date);
        }

        // Filter by disposition
        if ($disposition = $request->get('disposition')) {
            $query->where('disposition', $disposition);
        }

        $scheduledCalls = $query->orderBy('scheduled_at')->paginate(20)->withQueryString();
        $agents = $user->isAdmin()
            ? \App\Models\User::where('tenant_id', $user->tenant_id)
                              ->whereHas('role', fn($q) => $q->where('name', 'agent'))
                              ->get()
            : collect();

        return view('calls.schedule', compact('scheduledCalls', 'agents'));
    }

    /**
     * Log a call for a lead (AJAX)
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'lead_id'      => 'required|exists:leads,id',
            'disposition'  => 'required|in:customer,callback,no_answer,voicemail,wrong_number,not_interested,busy,disconnected',
            'called_at'    => 'required|date',
            'duration'     => 'nullable|integer|min:0',
            'notes'        => 'nullable|string',
            'scheduled_at' => 'nullable|date|after_or_equal:today',
        ]);

        // Auto-schedule if disposition is callback or customer
        $isScheduled = false;
        if (in_array($validated['disposition'], ['callback', 'customer']) && !empty($validated['scheduled_at'])) {
            $isScheduled = true;
        }

        $call = Call::create([
            ...$validated,
            'agent_id'     => $user->id,
            'direction'    => 'outbound',
            'is_scheduled' => $isScheduled,
            'completed'    => false,
        ]);

        // Update lead status based on disposition
        $lead = Lead::find($validated['lead_id']);
        if ($validated['disposition'] === 'customer') {
            $lead->update(['status' => 'closed_won']);
        } elseif ($lead->status === 'new') {
            $lead->update(['status' => 'contacted']);
        }

        $call->load(['lead', 'agent']);

        return response()->json([
            'success' => true,
            'call'    => $call,
            'message' => 'Call logged successfully' . ($isScheduled ? ' and scheduled.' : '.'),
        ]);
    }

    /**
     * Mark a scheduled call as completed
     */
    public function complete(Request $request, Call $call)
    {
        $user = Auth::user();

        $leadAgentId = $call->lead?->assigned_to;

        if (!$user->isAdmin() && $call->agent_id !== $user->id && $leadAgentId !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $call->update(['completed' => true]);

        return response()->json(['success' => true]);
    }


    /**
     * Get calls for a specific lead (AJAX)
     */
    public function forLead(Lead $lead)
    {
        $user = Auth::user();

        if (!$user->isAdmin() && $lead->assigned_to !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $calls = $lead->calls()->with('agent')->orderBy('called_at', 'desc')->get();

        return response()->json($calls);
    }
}
