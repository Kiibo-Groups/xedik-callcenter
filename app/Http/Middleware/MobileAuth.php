<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (empty($bearer)) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $tokenRecord = PersonalToken::with('user')
            ->where('token', hash('sha256', $bearer))
            ->first();

        if ($tokenRecord === null || $tokenRecord->isExpired() || $tokenRecord->user === null) {
            return response()->json(['success' => false, 'error' => 'Invalid or expired token.'], 401);
        }

        if (!$tokenRecord->user->is_active) {
            return response()->json(['success' => false, 'error' => 'Account is inactive.'], 403);
        }

        // Touch last_used_at without triggering updated_at
        PersonalToken::withoutTimestamps(fn () =>
            $tokenRecord->update(['last_used_at' => now()])
        );

        $request->attributes->set('mobile_token', $tokenRecord);
        $request->attributes->set('mobile_user', $tokenRecord->user);
        $request->attributes->set('mobile_tenant_id', $tokenRecord->tenant_id);

        return $next($request);
    }
}
