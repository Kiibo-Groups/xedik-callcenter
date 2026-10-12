<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Lead;
use Illuminate\Console\Command;

class AssignExistingLeadCampaigns extends Command
{
    protected $signature   = 'campaigns:assign-existing';
    protected $description = 'Assign campaigns to existing leads based on their notes field';

    public function handle(): void
    {
        $leads = Lead::whereNull('campaign_id')->get();

        if ($leads->isEmpty()) {
            $this->info('No leads without campaign found.');
            return;
        }

        $this->info("Found {$leads->count()} leads without campaign. Processing...\n");

        $assigned  = 0;
        $unmatched = 0;
        $default   = Campaign::getDefault();

        foreach ($leads as $lead) {
            // Extract campaign name from notes:
            // "Created from dialer. Campaign: gBPO TLMK PVD OB"
            $dialerCampaign = null;
            if ($lead->notes && preg_match('/Campaign:\s*(.+)$/i', $lead->notes, $matches)) {
                $dialerCampaign = trim($matches[1]);
            }

            $campaign = null;

            if ($dialerCampaign) {
                $campaign = Campaign::matchFromDialer($dialerCampaign);
            }

            // Fallback to default campaign if no pattern match
            if (!$campaign && $default) {
                $campaign = $default;
            }

            if ($campaign) {
                $lead->update(['campaign_id' => $campaign->id]);
                $tag = $dialerCampaign ? "(dialer: {$dialerCampaign})" : '(default fallback)';
                $this->line("  ✅  Lead #{$lead->id} ({$lead->phone}) → <info>{$campaign->name}</info> {$tag}");
                $assigned++;
            } else {
                $this->line("  ⚠️   Lead #{$lead->id} ({$lead->phone}) → <comment>No match — no default campaign set</comment>");
                $unmatched++;
            }
        }

        $this->newLine();
        $this->info("Done!  Assigned: {$assigned}  |  Unmatched: {$unmatched}");

        if ($unmatched > 0) {
            $this->warn("Tip: mark one campaign as Default to auto-assign unmatched leads,");
            $this->warn("or assign the remaining {$unmatched} lead(s) manually from the lead edit screen.");
        }
    }
}
