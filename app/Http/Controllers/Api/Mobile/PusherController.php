<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pusher\Pusher;

class PusherController extends Controller
{
    public function auth(Request $request): JsonResponse
    {
        $socketId    = (string) $request->input('socket_id', '');
        $channelName = (string) $request->input('channel_name', '');
        $user        = $request->attributes->get('mobile_user');

        // Only allow subscribing to the agent's own private channel
        if ($channelName !== 'private-agent.' . $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden channel.'], 403);
        }

        $pusher = new Pusher(
            (string) config('broadcasting.connections.pusher.key'),
            (string) config('broadcasting.connections.pusher.secret'),
            (string) config('broadcasting.connections.pusher.app_id'),
            ['cluster' => (string) config('broadcasting.connections.pusher.options.cluster')]
        );

        $auth = $pusher->authorizeChannel($channelName, $socketId);

        return response()->json(json_decode($auth, true));
    }
}
