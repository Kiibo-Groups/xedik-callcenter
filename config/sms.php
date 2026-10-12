<?php

declare(strict_types=1);

return [
    /**
     * Mapa de provider key (usado en config y en la ruta /api/webhooks/sms/{provider}/...)
     * a la clase adaptadora. Agregar un proveedor nuevo (Twilio, Telnyx, SMS Masivos MX)
     * es agregar una línea aquí + una clase que implemente SmsProviderInterface —
     * cero cambios en SmsService, ConsoleController, rutas o el motor omnicanal.
     */
    'providers' => [
        'didww' => \App\Services\Sms\Providers\DidwwSmsProvider::class,
    ],

    // Tope inicial recomendado de segmentos para envío P2P manual desde Quick Actions.
    'max_p2p_segments' => 3,

    'stop_keywords' => ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'],
];
