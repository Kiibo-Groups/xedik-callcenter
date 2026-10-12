<?php

namespace App\Console\Commands;

use App\Services\AgentStatusService;
use Illuminate\Console\Command;

class CleanupAgentSessions extends Command
{
    protected $signature   = 'agents:cleanup';
    protected $description = 'Auto-offline agents that stopped sending pings';

    public function __construct(private AgentStatusService $statusService)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->statusService->handleOfflineAgents();
        $this->info('Agent sessions cleanup done.');
    }
}