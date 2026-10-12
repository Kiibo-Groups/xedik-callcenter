<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\ZernioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Flujo OAuth headless para conectar cuentas de Facebook/Instagram vía Zernio.
 * Toda la UI es nuestra — el usuario nunca ve branding de terceros.
 */
class SocialConnectController extends Controller
{
    public function __construct(private readonly ZernioService $zernio) {}

    /**
     * POST — inicia la conexión: asegura el profile del tenant y redirige al OAuth.
     */
    public function redirect(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_social_accounts'), 403);

        $request->validate([
            'platform'      => 'required|in:' . implode(',', SocialAccount::PLATFORMS),
            'omni_queue_id' => 'nullable|exists:omni_queues,id',
        ]);

        $tenant = auth()->user()->tenant;
        abort_unless($tenant, 403, 'No tenant associated with this user');

        try {
            $profileId = $this->zernio->ensureProfile($tenant);

            session(['social_connect' => [
                'platform'      => $request->platform,
                'omni_queue_id' => $request->omni_queue_id,
                'profile_id'    => $profileId,
            ]]);

            $authUrl = $this->zernio->getConnectUrl(
                $request->platform,
                $profileId,
                route('social-accounts.callback')
            );

            return redirect()->away($authUrl);

        } catch (\Throwable $e) {
            Log::error('Social connect: failed to start OAuth — ' . $e->getMessage());
            return redirect()->route('social-accounts.index')
                             ->with('error', 'Could not start the connection. Please try again.');
        }
    }

    /**
     * GET — callback genérico del OAuth (todas las plataformas).
     * FB headless llega con: profileId, tempToken, userProfile, connect_token, platform, step.
     * Cuenta creada directa (IG / flujo estándar) llega con: connected, accountId, username.
     */
    public function callback(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_social_accounts'), 403);

        $pending = session('social_connect');

        if (!$pending) {
            return redirect()->route('social-accounts.index')
                             ->with('error', 'Connection session expired — please try again.');
        }

        try {
            // Caso 1: la cuenta ya fue creada (Instagram directo o flujo estándar)
            if ($request->filled('accountId')) {
                $this->persistFromZernio($pending, (string) $request->accountId);
                session()->forget('social_connect');

                return redirect()->route('social-accounts.index')
                                 ->with('success', 'Account connected successfully.');
            }

            // Caso 2: Facebook headless → selección de página con UI propia
            if ($request->get('step') === 'select_page' && $request->filled('tempToken')) {
                $profileId   = $request->get('profileId', $pending['profile_id']);
                $userProfile = json_decode(urldecode((string) $request->get('userProfile', '')), true) ?? [];

                $pages = $this->zernio->listFacebookPages($profileId, (string) $request->tempToken);

                session(['social_connect' => array_merge($pending, [
                    'profile_id'   => $profileId,
                    'temp_token'   => (string) $request->tempToken,
                    'user_profile' => $userProfile,
                ])]);

                return view('social_accounts.select_page', ['pages' => $pages]);
            }

            Log::warning('Social connect: unrecognized callback payload', $request->all());

            return redirect()->route('social-accounts.index')
                             ->with('error', 'The connection could not be completed.');

        } catch (\Throwable $e) {
            Log::error('Social connect: callback error — ' . $e->getMessage());
            return redirect()->route('social-accounts.index')
                             ->with('error', 'The connection could not be completed. Please try again.');
        }
    }

    /**
     * POST — el admin eligió la página de Facebook a conectar.
     */
    public function selectPage(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_social_accounts'), 403);

        $request->validate(['page_id' => 'required|string|max:50']);

        $pending = session('social_connect');

        if (!$pending || empty($pending['temp_token'])) {
            return redirect()->route('social-accounts.index')
                             ->with('error', 'Connection session expired — please try again.');
        }

        try {
            $data = $this->zernio->selectFacebookPage(
                $pending['profile_id'],
                $request->page_id,
                $pending['temp_token'],
                $pending['user_profile'] ?? []
            );

            SocialAccount::updateOrCreate(
                ['zernio_account_id' => $data['account_id']],
                [
                    'tenant_id'         => auth()->user()->tenant_id,
                    'omni_queue_id'     => $pending['omni_queue_id'] ?? null,
                    'platform'          => $data['platform'] ?: 'facebook',
                    'zernio_profile_id' => $pending['profile_id'],
                    'page_id'           => $request->page_id,
                    'username'          => $data['username'],
                    'display_name'      => $data['display_name'] ?: ($data['page_name'] ?? 'Facebook Page'),
                    'picture_url'       => $data['picture_url'],
                    'is_active'         => true,
                ]
            );

            session()->forget('social_connect');

            Log::info("Social connect: account {$data['account_id']} ({$data['platform']}) connected for tenant " . auth()->user()->tenant_id);

            return redirect()->route('social-accounts.index')
                             ->with('success', "Page \"{$data['display_name']}\" connected successfully.");

        } catch (\Throwable $e) {
            Log::error('Social connect: selectPage error — ' . $e->getMessage());
            return redirect()->route('social-accounts.index')
                             ->with('error', 'Could not connect the selected page. Please try again.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Reconcilia una account ya creada en Zernio (ej. Instagram directo)
     * consultando listAccounts del profile para obtener los datos completos.
     */
    private function persistFromZernio(array $pending, string $accountId): void
    {
        $account = collect($this->zernio->listAccounts($pending['profile_id']))
            ->firstWhere('account_id', $accountId);

        if (!$account) {
            throw new \RuntimeException("Zernio account {$accountId} not found in profile {$pending['profile_id']}");
        }

        SocialAccount::updateOrCreate(
            ['zernio_account_id' => $account['account_id']],
            [
                'tenant_id'         => auth()->user()->tenant_id,
                'omni_queue_id'     => $pending['omni_queue_id'] ?? null,
                'platform'          => $account['platform'],
                'zernio_profile_id' => $pending['profile_id'],
                'page_id'           => null,
                'username'          => $account['username'],
                'display_name'      => $account['display_name'] ?: ($account['username'] ?? ucfirst($account['platform'])),
                'picture_url'       => $account['picture_url'],
                'is_active'         => true,
            ]
        );

        Log::info("Social connect: account {$accountId} ({$account['platform']}) connected for tenant " . auth()->user()->tenant_id);
    }
}
