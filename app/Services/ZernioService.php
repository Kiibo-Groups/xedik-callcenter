<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use GuzzleHttp\Client;
use Zernio\Api\AccountsApi;
use Zernio\Api\ConnectApi;
use Zernio\Api\MessagesApi;
use Zernio\Api\ProfilesApi;
use Zernio\Api\WebhooksApi;
use Zernio\Configuration;
use Zernio\Model\CreateWebhookSettingsRequest;
use Zernio\Model\SelectFacebookPageRequest;
use Zernio\Model\SelectFacebookPageRequestUserProfile;
use Zernio\Model\SendInboxMessageRequest;

/**
 * Único punto de contacto con la API de Zernio (canales Facebook/Instagram).
 *
 * Gotchas encapsulados aquí (no repetir fuera):
 * - headless debe serializarse como string 'true' → BOOLEAN_FORMAT_STRING obligatorio.
 * - El Guzzle del SDK no tiene timeout por default.
 * - Getters inconsistentes entre response models del SDK generado.
 */
class ZernioService
{
    private Configuration $config;
    private Client $http;

    public function __construct()
    {
        $this->config = Configuration::getDefaultConfiguration()
            ->setAccessToken((string) config('services.zernio.key'))
            ->setBooleanFormatForQueryString(Configuration::BOOLEAN_FORMAT_STRING);

        $this->http = new Client(['timeout' => 25, 'connect_timeout' => 10]);
    }

    // ─── Profiles ────────────────────────────────────────────────────────────

    /**
     * Devuelve el profile de Zernio del tenant, creándolo la primera vez.
     */
    public function ensureProfile(Tenant $tenant): string
    {
        if ($tenant->zernio_profile_id) {
            return $tenant->zernio_profile_id;
        }

        $api       = new ProfilesApi($this->http, $this->config);
        $profileId = $api->createProfile(['name' => $tenant->name])->getProfile()->getId();

        $tenant->update(['zernio_profile_id' => $profileId]);

        return $profileId;
    }

    // ─── Connect (OAuth headless) ────────────────────────────────────────────

    /**
     * URL de OAuth para conectar una plataforma al profile del tenant.
     */
    public function getConnectUrl(string $platform, string $profileId, string $redirectUrl): string
    {
        $api      = new ConnectApi($this->http, $this->config);
        $response = $api->getConnectUrl($platform, $profileId, $redirectUrl, true);

        $authUrl = method_exists($response, 'getAuthUrl') ? $response->getAuthUrl() : null;

        if (!$authUrl) {
            throw new \RuntimeException('Zernio: no authUrl in connect response');
        }

        return $authUrl;
    }

    /**
     * Páginas de Facebook disponibles tras el OAuth (paso select_page).
     *
     * @return array<int, array{id: string|null, name: string|null}>
     */
    public function listFacebookPages(string $profileId, string $tempToken): array
    {
        $api   = new ConnectApi($this->http, $this->config);
        $pages = $api->listFacebookPages($profileId, $tempToken)->getPages();

        return collect($pages)->map(fn($p) => [
            'id'   => method_exists($p, 'getId') ? $p->getId() : null,
            'name' => method_exists($p, 'getName') ? $p->getName() : null,
        ])->all();
    }

    /**
     * Conecta la página elegida y devuelve los datos de la account creada.
     *
     * @param array $userProfile decodificado del param userProfile del callback
     * @return array{account_id: string, platform: string, username: ?string, display_name: ?string, picture_url: ?string, page_name: ?string}
     */
    public function selectFacebookPage(string $profileId, string $pageId, string $tempToken, array $userProfile): array
    {
        $api = new ConnectApi($this->http, $this->config);

        $request = new SelectFacebookPageRequest([
            'profile_id'   => $profileId,
            'page_id'      => $pageId,
            'temp_token'   => $tempToken,
            'user_profile' => new SelectFacebookPageRequestUserProfile([
                'id'              => $userProfile['id'] ?? '',
                'name'            => $userProfile['displayName'] ?? $userProfile['username'] ?? '',
                'profile_picture' => $userProfile['profilePicture'] ?? '',
            ]),
        ]);

        // OJO: getAccountId(), no getId() — quirk del SDK generado
        $account = $api->selectFacebookPage($request)->getAccount();

        return [
            'account_id'   => $account->getAccountId(),
            'platform'     => $account->getPlatform(),
            'username'     => $account->getUsername(),
            'display_name' => $account->getDisplayName(),
            'picture_url'  => $account->getProfilePicture(),
            'page_name'    => $account->getSelectedPageName(),
        ];
    }

    /**
     * Accounts conectadas a un profile (para reconciliar tras conexión directa, ej. Instagram).
     *
     * @return array<int, array{account_id: string, platform: string, username: ?string, display_name: ?string, picture_url: ?string, is_active: bool}>
     */
    public function listAccounts(string $profileId): array
    {
        $api      = new AccountsApi($this->http, $this->config);
        $accounts = $api->listAccounts($profileId)->getAccounts() ?? [];

        return collect($accounts)->map(fn($a) => [
            'account_id'   => $a->getId(),
            'platform'     => $a->getPlatform(),
            'username'     => $a->getUsername(),
            'display_name' => $a->getDisplayName(),
            'picture_url'  => $a->getProfilePicture(),
            'is_active'    => (bool) $a->getIsActive(),
        ])->all();
    }

    // ─── Messaging ───────────────────────────────────────────────────────────

    /**
     * Envía un mensaje de texto a una conversación del inbox.
     *
     * @return array{message_id: ?string, conversation_id: ?string}
     */
    public function sendMessage(string $zernioConversationId, string $zernioAccountId, string $text): array
    {
        $api = new MessagesApi($this->http, $this->config);

        $response = $api->sendInboxMessage($zernioConversationId, new SendInboxMessageRequest([
            'account_id' => $zernioAccountId,
            'message'    => $text,
        ]));

        $data = $response->getData();

        return [
            'message_id'      => $data?->getMessageId(),
            'conversation_id' => $data?->getConversationId(),
        ];
    }

    // ─── Webhooks ────────────────────────────────────────────────────────────

    /**
     * Registra (o reutiliza) el webhook global de la cuenta Zernio.
     * Idempotente: si ya existe uno apuntando a $url, no crea otro.
     */
    public function registerWebhook(string $url, string $secret, array $events): array
    {
        $api = new WebhooksApi($this->http, $this->config);

        $existing = $this->currentWebhooks();
        foreach ($existing as $hook) {
            if (($hook['url'] ?? null) === $url) {
                return ['created' => false, 'webhook' => $hook];
            }
        }

        $response = $api->createWebhookSettings(new CreateWebhookSettingsRequest([
            'name'      => 'xedik CRM',
            'url'       => $url,
            'secret'    => $secret,
            'events'    => $events,
            'is_active' => true,
        ]));

        return ['created' => true, 'webhook' => json_decode((string) json_encode($response->jsonSerialize()), true)];
    }

    /**
     * Webhooks actualmente registrados en la cuenta.
     *
     * @return array<int, array{id: ?string, name: ?string, url: ?string, events: array, is_active: bool}>
     */
    public function currentWebhooks(): array
    {
        $api   = new WebhooksApi($this->http, $this->config);
        $hooks = $api->getWebhookSettings()->getWebhooks() ?? [];

        return collect($hooks)->map(fn($h) => [
            'id'        => $h->getId(),
            'name'      => $h->getName(),
            'url'       => $h->getUrl(),
            'events'    => $h->getEvents() ?? [],
            'is_active' => (bool) $h->getIsActive(),
        ])->all();
    }
}
