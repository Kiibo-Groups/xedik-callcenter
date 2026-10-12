<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AccessToken;
use App\Models\Scopes\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TokenUrlAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Accept token from query string (GET) or form body (POST)
        $tokenStr = $request->query('token') ?? $request->input('token');

        if (empty($tokenStr)) {
            Log::warning('[API] Request rejected — no token', [
                'method' => $request->method(),
                'path'   => $request->path(),
                'ip'     => $request->ip(),
            ]);
            return $this->deny($request, 'No access token provided.');
        }

        $accessToken = AccessToken::withoutGlobalScope(TenantScope::class)
            ->where('token', $tokenStr)
            ->where('is_active', true)
            ->first();

        if ($accessToken === null) {
            Log::warning('[API] Request rejected — invalid token', [
                'method' => $request->method(),
                'path'   => $request->path(),
                'ip'     => $request->ip(),
            ]);
            return $this->deny($request, 'Invalid or revoked access token.');
        }

        Log::info('[API] Request', [
            'method'    => $request->method(),
            'path'      => $request->path(),
            'token'     => $accessToken->label,
            'tenant_id' => $accessToken->tenant_id,
            'ip'        => $request->ip(),
        ]);

        $request->attributes->set('access_token', $accessToken);

        return $next($request);
    }

    private function deny(Request $request, string $message): Response
    {
        return response()->view('dialer.unauthorized', ['message' => $message], 403);
    }
}
