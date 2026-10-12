<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Canal privado por agente — solo el propio usuario puede suscribirse.
| Pusher verificará este endpoint antes de permitir la conexión.
|
*/

Broadcast::channel('agent.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
