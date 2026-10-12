<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PreviewDialerOffer;
use App\Models\VoiceCall;
use App\Services\PreviewDialerLifecycleService;
use Illuminate\Console\Command;

class RecoverStalePreviewOffers extends Command
{
    protected $signature = 'preview:recover-stale-offers';
    protected $description = 'Finalize accepted Preview Dialer offers whose call has ended or never started';

    public function handle(PreviewDialerLifecycleService $lifecycle): int
    {
        $timeoutSeconds = (int) config('preview_dialer.accepted_start_timeout_seconds', 120);
        $limit = (int) config('preview_dialer.stale_scan_limit', 50);

        $stale = PreviewDialerOffer::withoutGlobalScopes()
            ->where('status', 'accepted')
            ->where('accepted_at', '<=', now()->subSeconds($timeoutSeconds))
            ->limit($limit)
            ->get();

        if ($stale->isEmpty()) {
            return self::SUCCESS;
        }

        $this->info("Found {$stale->count()} stale accepted offer(s).");

        foreach ($stale as $offer) {
            $voiceCall = $offer->call_uuid
                ? VoiceCall::withoutGlobalScopes()
                    ->where('tenant_id', $offer->tenant_id)
                    ->where('call_uuid', $offer->call_uuid)
                    ->first()
                : null;

            if ($voiceCall && in_array($voiceCall->call_state, VoiceCall::TERMINAL_STATES, true)) {
                $lifecycle->finalizeAtEnd($voiceCall);
                $this->line("  Offer #{$offer->id}: finalized from terminal VoiceCall ({$voiceCall->call_state}).");
            } elseif (!$voiceCall) {
                $lifecycle->finalizeStaleOffer($offer);
                $this->line("  Offer #{$offer->id}: finalized (no VoiceCall found).");
            } elseif ($lifecycle->recoverStaleUnansweredCall($offer, $voiceCall)) {
                $this->line("  Offer #{$offer->id}: recovered stale unanswered VoiceCall ({$voiceCall->call_state}).");
            } else {
                $this->line("  Offer #{$offer->id}: skipped (VoiceCall in '{$voiceCall->call_state}').");
            }
        }

        return self::SUCCESS;
    }
}
