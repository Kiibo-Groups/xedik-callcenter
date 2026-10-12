<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\NewConversationReceived;
use App\Models\AgentSession;
use App\Models\Conversation;
use App\Models\OmniQueue;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AssignConversations extends Command
{
    protected $signature   = 'conversations:assign';
    protected $description = 'Assign pending conversations to available agents';

    public function handle(): void
    {
        // Paso 1: Reactivar conversaciones reservadas cuyo agente ya está ready
        $reserved = Conversation::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                                ->where('status', 'pending')
                                ->whereNotNull('assigned_to')
                                ->notAiControlled()
                                ->get();

        foreach ($reserved as $conversation) {
            // Defensive re-check: guards against ai_bot_active flipping to true
            // between the query above and this loop iteration (race condition).
            if ($conversation->ai_bot_active) {
                Log::info("AssignConversations: conversation #{$conversation->id} AI-controlled, reserved-agent reactivation skipped");
                continue;
            }

            $ready = AgentSession::where('user_id', $conversation->assigned_to)
                                 ->where('status', 'ready')
                                 ->exists();

            if ($ready) {
                Conversation::withoutTimestamps(fn() => $conversation->update([
                    'status'      => 'active',
                    'assigned_at' => now(),
                    'read_at'     => null,
                ]));
                Log::info("AssignConversations: conversation #{$conversation->id} re-activated for reserved agent #{$conversation->assigned_to}");
                $this->info("  Conversation #{$conversation->id} re-activated → agent #{$conversation->assigned_to}");

                event(new NewConversationReceived($conversation->fresh()));
            }
        }

        // Paso 2: Asignar conversaciones sin agente a agentes disponibles
        $pending = Conversation::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                               ->with(['omniQueue.agents'])
                               ->where('status', 'pending')
                               ->whereNull('assigned_to')
                               ->notAiControlled()
                               ->get();

        if ($pending->isEmpty()) {
            $this->info('No unassigned pending conversations.');
            return;
        }

        $this->info("Found {$pending->count()} unassigned pending conversations.");
        foreach ($pending as $conversation) {
            $this->assignConversation($conversation);
        }
    }

    private function assignConversation(Conversation $conversation): void
    {
        // Defensive re-check: guards against ai_bot_active flipping to true
        // between the query in handle() and this call (race condition) — this
        // single check covers direct/least_busy/round_robin, all funnel here.
        if ($conversation->ai_bot_active) {
            Log::info("AssignConversations: conversation #{$conversation->id} held by AI bot");
            return;
        }

        $queue = $conversation->omniQueue;

        if (!$queue) {
            $this->warn("  Conversation #{$conversation->id} has no queue — skipping.");
            return;
        }

        // Solo agentes activos en la queue Y en estado ready
        $agents = $queue->agents()
                        ->wherePivot('active', true)
                        ->whereHas('agentSession', function($q) {
                            $q->where('status', 'ready');
                        })
                        ->get();

        if ($agents->isEmpty()) {
            $this->warn("  Queue '{$queue->name}' has no ready agents — skipping.");
            return;
        }

        $agent = match($queue->assignment_mode) {
            'direct'     => $this->assignDirect($agents, $queue),
            'least_busy' => $this->assignLeastBusy($agents, $queue),
            default      => $this->assignRoundRobin($agents, $queue),
        };

        if (!$agent) {
            $this->warn("  Could not find agent for conversation #{$conversation->id}.");
            return;
        }

        Conversation::withoutTimestamps(fn() => $conversation->update([
            'assigned_to' => $agent->id,
            'assigned_at' => now(),
            'status'      => 'active',
            'read_at'     => null,
        ]));

        // Asignar el lead al agente si aún no tiene dueño
        if ($conversation->lead_id) {
            \App\Models\Lead::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->where('id', $conversation->lead_id)
                ->whereNull('assigned_to')
                ->update(['assigned_to' => $agent->id]);
        }

        Log::info("AssignConversations: conversation #{$conversation->id} → {$agent->name} [{$queue->assignment_mode}]");
        $this->info("  Conversation #{$conversation->id} → {$agent->name} [{$queue->assignment_mode}]");

        event(new NewConversationReceived($conversation->fresh()));

        // Broadcast al widget si es conversación de chat
        if ($conversation->channel === 'chat') {
            $session = \App\Models\WebchatSession::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->where('conversation_id', $conversation->id)
                ->first();

            if ($session) {
                try {
                    $pusher = new \Pusher\Pusher(
                        config('broadcasting.connections.pusher.key'),
                        config('broadcasting.connections.pusher.secret'),
                        config('broadcasting.connections.pusher.app_id'),
                        ['cluster' => config('broadcasting.connections.pusher.options.cluster')]
                    );
                    $pusher->trigger('webchat.' . $session->session_token, 'agent-assigned', [
                        'agent_name' => $agent->name,
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('AssignConversations Pusher error: ' . $e->getMessage());
                }
            }
        }
    }

    private function assignDirect($agents, OmniQueue $queue): ?User
    {
        if ($queue->default_agent_id) {
            $default = $agents->firstWhere('id', $queue->default_agent_id);
            if ($default) return $default;
        }
        return $agents->first();
    }

    private function assignLeastBusy($agents, OmniQueue $queue): ?User
    {
        return $agents->sortBy(function ($agent) use ($queue) {
            return Conversation::where('assigned_to', $agent->id)
                               ->where('omni_queue_id', $queue->id)
                               ->where('status', 'active')
                               ->count();
        })->first();
    }

    private function assignRoundRobin($agents, OmniQueue $queue): ?User
    {
        return $agents->sortBy(function ($agent) use ($queue) {
            $last = Conversation::where('assigned_to', $agent->id)
                                ->where('omni_queue_id', $queue->id)
                                ->latest('assigned_at')
                                ->value('assigned_at');
            return $last ?? '1970-01-01';
        })->first();
    }
}