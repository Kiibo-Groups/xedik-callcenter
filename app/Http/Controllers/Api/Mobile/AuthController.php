<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PersonalToken;
use App\Models\User;
use App\Services\AgentStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // ── POST /api/mobile/login ────────────────────────────────────────────────

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'device'   => 'nullable|string|max:200',
        ]);

        $user = User::where('email', $request->email)
            ->where('is_active', true)
            ->first();

        if ($user === null || !Hash::check($request->password, $user->password)) {
            return response()->json(['success' => false, 'error' => 'Invalid credentials.'], 401);
        }

        if ($user->tenant_id === null) {
            return response()->json(['success' => false, 'error' => 'Account has no tenant assigned.'], 403);
        }

        $rawToken = Str::random(64);

        PersonalToken::create([
            'user_id'   => $user->id,
            'tenant_id' => $user->tenant_id,
            'token'     => hash('sha256', $rawToken),
            'device'    => $request->input('device'),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'success' => true,
            'token'   => $rawToken,
            'user'    => $this->formatUser($user),
        ]);
    }

    // ── POST /api/mobile/logout ───────────────────────────────────────────────

    public function logout(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');
        if ($user) {
            app(AgentStatusService::class)->changeStatus($user, 'offline');
        }

        $tokenRecord = $request->attributes->get('mobile_token');
        $tokenRecord?->delete();

        return response()->json(['success' => true]);
    }

    // ── GET /api/mobile/me ────────────────────────────────────────────────────

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');

        return response()->json([
            'success' => true,
            'user'    => $this->formatUser($user),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function formatUser(User $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'phone'        => $user->phone_user_id,
            'tenant_id'    => $user->tenant_id,
            'is_superadmin' => $user->is_superadmin,
            'signature'    => $user->signature,
        ];
    }
}
