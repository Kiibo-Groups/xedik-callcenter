<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'webhook/call',
            'webhooks/whatsapp',
            'webhooks/zernio',
            'api/webhooks/sms/*',
            'api/webchat/*',
            'webchat/widget.js',
            'webchat-test/*',
            'dialer/lead-view/save',
        ]);
        $middleware->appendToGroup('web', \App\Http\Middleware\CheckUserActive::class);
        
        $middleware->alias([
            'permission'  => \App\Http\Middleware\CheckPermission::class,
            'token.auth'  => \App\Http\Middleware\TokenUrlAuth::class,
            'mobile.auth' => \App\Http\Middleware\MobileAuth::class,
            'voice.performance' =>
                \App\Http\Middleware\VoicePerformanceMiddleware::class,
        ]);

        $middleware->api(prepend: \Illuminate\Http\Middleware\HandleCors::class);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            if ($response->getStatusCode() === 419) {
                return redirect()->route('login')->withErrors([
                    'email' => 'Your session expired. Please sign in again.',
                ]);
            }
            return $response;
        });
    })->create();
