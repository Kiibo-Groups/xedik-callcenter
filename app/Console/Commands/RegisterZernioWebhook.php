<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ZernioService;
use Illuminate\Console\Command;

class RegisterZernioWebhook extends Command
{
    protected $signature   = 'zernio:register-webhook
                              {--url= : Override webhook URL (default: APP_URL/webhooks/zernio)}
                              {--list : Only list current webhooks, do not register}';

    protected $description = 'Register (idempotent) the global Zernio webhook for Facebook/Instagram messages';

    public function handle(ZernioService $zernio): int
    {
        if ($this->option('list')) {
            $hooks = $zernio->currentWebhooks();
            $this->table(
                ['ID', 'Name', 'URL', 'Events', 'Active'],
                collect($hooks)->map(fn($h) => [
                    $h['id'], $h['name'], $h['url'],
                    implode(', ', $h['events']),
                    $h['is_active'] ? 'yes' : 'no',
                ])->all()
            );
            return self::SUCCESS;
        }

        $url    = $this->option('url') ?: rtrim((string) config('app.url'), '/') . '/webhooks/zernio';
        $secret = (string) config('services.zernio.webhook_secret');

        if ($secret === '') {
            $this->error('ZERNIO_WEBHOOK_SECRET is not set in .env — aborting.');
            return self::FAILURE;
        }

        if (!str_starts_with($url, 'https://')) {
            $this->error("Webhook URL must be HTTPS — got: {$url}");
            return self::FAILURE;
        }

        $this->info("Registering webhook: {$url}");

        $result = $zernio->registerWebhook($url, $secret, [
            'message.received',
            'account.disconnected',
        ]);

        if ($result['created']) {
            $this->info('Webhook registered successfully.');
        } else {
            $this->warn('A webhook with this URL already exists — nothing to do.');
        }

        return self::SUCCESS;
    }
}
