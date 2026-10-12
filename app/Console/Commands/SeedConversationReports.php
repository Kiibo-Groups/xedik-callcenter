<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Conversation;
use App\Models\ConversationReport;

class SeedConversationReports extends Command
{
    protected $signature   = 'conversations:seed-reports {--reprocess : Update existing reports recalculating from actual messages}';
    protected $description = 'Seed conversation_reports from existing resolved conversations';

    public function handle()
    {
        $reprocess = $this->option('reprocess');

        $query = Conversation::with([
            'lead', 'assignedAgent',
            'messages' => fn($q) => $q->reorder()->orderBy('sent_at', 'asc')->limit(1)
        ])->where('status', 'resolved');

        if (!$reprocess) {
            $query->whereNotExists(function($q) {
                $q->select(\Illuminate\Support\Facades\DB::raw(1))
                  ->from('conversation_reports')
                  ->whereColumn('conversation_reports.conversation_id', 'conversations.id');
            });
        }

        $conversations = $query->get();

        $this->info(($reprocess ? 'Reprocessing' : 'Seeding') . " {$conversations->count()} conversations...");
        $bar = $this->output->createProgressBar($conversations->count());

        foreach ($conversations as $conv) {
            try {
                $firstMsg     = $conv->messages->first();
                $isOutbound   = $firstMsg?->direction === 'outbound';
                $contactEmail = $isOutbound ? $firstMsg?->to_email : $conv->from_email;
                $leadName     = $conv->lead
                    ? trim($conv->lead->first_name . ' ' . $conv->lead->last_name)
                    : null;
                $durationSeconds = ($conv->wait_since && $conv->resolved_at)
                    ? (int) $conv->wait_since->diffInSeconds($conv->resolved_at)
                    : null;
                $messageCount  = $conv->messages()->count();
                $inboundCount  = $conv->messages()->where('direction','inbound')->count();
                $outboundCount = $conv->messages()->where('direction','outbound')->count();
                $preview = $firstMsg
                    ? \Str::limit(strip_tags($firstMsg->body_text ?? $firstMsg->body_html ?? ''), 100)
                    : null;

                $data = [
                    'agent_id'               => $conv->assigned_to,
                    'agent_name'             => $conv->assignedAgent?->name,
                    'lead_id'                => $conv->lead_id,
                    'lead_name'              => $leadName,
                    'contact_email'          => $contactEmail,
                    'channel'                => $conv->channel,
                    'direction'              => $isOutbound ? 'outbound' : 'inbound',
                    'subject'                => $conv->subject,
                    'first_message_preview'  => $preview,
                    'disposition'            => $conv->disposition,
                    'message_count'          => $messageCount,
                    'inbound_message_count'  => $inboundCount,
                    'outbound_message_count' => $outboundCount,
                    'duration_seconds'       => $durationSeconds,
                    'wait_since'             => $conv->wait_since,
                    'resolved_at'            => $conv->resolved_at,
                ];

                ConversationReport::updateOrCreate(
                    ['conversation_id' => $conv->id],
                    $data
                );

                $bar->advance();
            } catch (\Throwable $e) {
                $this->error("Error conv #{$conv->id}: " . $e->getMessage());
            }
        }
        $bar->finish();
        $this->newLine();
        $this->info('Done!');
    }
}
