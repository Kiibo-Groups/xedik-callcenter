<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VoiceSession;
use App\Models\User;
use App\Services\Voice\VoiceSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 10;
    private const THROTTLE_MAX        = 5;
    private const THROTTLE_DECAY      = 60; // seconds

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect('/');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        // ── Rate limiting — 5 attempts per minute per IP+email ────────────────
        $throttleKey = Str::lower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::THROTTLE_MAX)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return redirect()->route('login')->withErrors([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ])->onlyInput('email');
        }

        // ── Account lockout check ─────────────────────────────────────────────
        $user = User::withoutGlobalScopes()->where('email', $credentials['email'])->first();

        if ($user && $user->isLocked()) {
            RateLimiter::hit($throttleKey, self::THROTTLE_DECAY);
            return redirect()->route('login')->withErrors([
                'email' => 'Account locked due to too many failed attempts. Contact your administrator.',
            ])->onlyInput('email');
        }

        // ── Attempt login ─────────────────────────────────────────────────────
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            if (! Auth::user()->is_active) {
                Auth::logout();
                return redirect()->route('login')->withErrors([
                    'email' => 'Your account has been deactivated. Contact your administrator.',
                ])->onlyInput('email');
            }

            // Reset rate limiter and failed attempts on success
            RateLimiter::clear($throttleKey);
            Auth::user()->update([
                'failed_login_attempts' => 0,
                'locked_at'             => null,
            ]);

            $request->session()->regenerate();

            app(\App\Services\AgentStatusService::class)
                ->changeStatus(Auth::user(), 'offline');

            $intended     = $request->session()->pull('url.intended', '/');
            $intendedPath = parse_url($intended, PHP_URL_PATH) ?? '/';
            if (str_starts_with($intendedPath, '/console/') && $intendedPath !== '/console') {
                $intended = '/';
            }

            return redirect($intended);
        }

        // ── Failed attempt ────────────────────────────────────────────────────
        RateLimiter::hit($throttleKey, self::THROTTLE_DECAY);

        if ($user) {
            $attempts = $user->failed_login_attempts + 1;
            $lockedAt = $attempts >= self::MAX_FAILED_ATTEMPTS ? now() : null;

            $user->update([
                'failed_login_attempts' => $attempts,
                'locked_at'             => $lockedAt,
            ]);

            if ($lockedAt) {
                return redirect()->route('login')->withErrors([
                    'email' => 'Account locked due to too many failed attempts. Contact your administrator.',
                ])->onlyInput('email');
            }
        }

        return redirect()->route('login')->withErrors([
            'email' => 'The credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        if (Auth::check()) {
            $user = Auth::user();

            app(\App\Services\AgentStatusService::class)
                ->changeStatus($user, 'offline');

            $this->revokeActiveVoiceSessions($user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    private function revokeActiveVoiceSessions(User $user): void
    {
        $sessions = VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'registered', 'active'])
            ->orderByDesc('last_seen_at')
            ->get();

        foreach ($sessions as $session) {
            try {
                app(VoiceSessionService::class)->revoke($session, 'web_logout');
            } catch (Throwable $e) {
                Log::warning('[QueueMemberAvailability] web logout voice session revoke failed', [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'voice_session_id' => $session->id,
                    'error_class' => $e::class,
                    'message' => Str::limit($e->getMessage(), 160, ''),
                ]);
            }
        }
    }
}
