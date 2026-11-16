<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\RestaurantOrder;
use App\Models\Tenant;
use App\Models\WhatsappAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RestaurantOrderService
{
    /**
     * Crea un registro en restaurant_orders a partir de un pedido confirmado
     * (por Voz IA, WhatsApp IA o Facebook/Instagram IA) y envía notificaciones
     * por WhatsApp con soporte automático de Plantilla (Template) cuando la
     * ventana de 24 horas de Meta está cerrada.
     */
    public function createOrderAndNotify(array $data): RestaurantOrder
    {
        $tenantId = (int) ($data['tenant_id'] ?? 6);
        $tenant = Tenant::find($tenantId) ?? Tenant::where('slug', 'tizon317')->first();
        if ($tenant) {
            $tenantId = $tenant->id;
        }

        $channel = (string) ($data['channel'] ?? 'voice');
        $rawText = (string) ($data['raw_text'] ?? '');
        $summary = (string) ($data['summary'] ?? '');
        $parsed  = $this->parseOrderDetails($rawText, $summary);

        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $tenant?->slug ?: 'ORD'), 0, 3)) ?: 'ORD';
        $nextSeq = (int) RestaurantOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->count() + 1;
        $orderNumber = sprintf('%s-%04d', $prefix, $nextSeq);

        $order = RestaurantOrder::withoutGlobalScopes()->create([
            'tenant_id'        => $tenantId,
            'order_number'     => $orderNumber,
            'channel'          => $channel,
            'customer_name'    => $data['customer_name'] ?? $parsed['customer_name'] ?? 'Cliente',
            'customer_phone'   => $data['customer_phone'] ?? null,
            'items_summary'    => $data['items_summary'] ?? $parsed['items_summary'],
            'delivery_type'    => $data['delivery_type'] ?? $parsed['delivery_type'],
            'delivery_address' => $data['delivery_address'] ?? $parsed['delivery_address'],
            'payment_method'   => $data['payment_method'] ?? $parsed['payment_method'],
            'total_amount'     => $data['total_amount'] ?? $parsed['total_amount'],
            'status'           => 'new',
            'notes'            => $rawText,
            'call_id'          => $data['call_id'] ?? null,
            'conversation_id'  => $data['conversation_id'] ?? null,
            'lead_id'          => $data['lead_id'] ?? null,
        ]);

        // Enviar notificaciones WhatsApp (Cliente + Cocina) con fallback a Plantilla si ventana 24h está cerrada
        $notifyCustomer = (bool) ($data['notify_customer'] ?? true);
        $notifyKitchen  = (bool) ($data['notify_kitchen'] ?? true);

        $this->sendOrderNotifications($order, $tenant, $notifyCustomer, $notifyKitchen);

        return $order;
    }

    /**
     * Extrae platillos, modalidad (domicilio/recoger), dirección, método de pago y total
     * tanto de un ticket estructurado de WhatsApp/Facebook como de una transcripción de Voz IA.
     */
    public function parseOrderDetails(string $text, string $summary = ''): array
    {
        $combined = $text . "\n" . $summary;
        $lower = mb_strtolower($combined);

        // 1. Modalidad: delivery vs pickup
        $deliveryType = 'delivery';
        if (preg_match('/(pasar a recoger|para recoger|recoger en sucursal|modalidad:\s*\*?recoger)/iu', $combined)
            && !preg_match('/(calle\s+[a-záéíóúñ]+.*n[úu]mero|env[íi]o a domicilio.*calle)/iu', $combined)) {
            $deliveryType = 'pickup';
        }

        // 2. Método de pago
        $paymentMethod = 'Efectivo';
        if (preg_match('/(tarjeta|terminal)/iu', $combined) && !preg_match('/pago en efectivo|ser[íi]a en efectivo|en efectivo est[áa] bien/iu', $combined)) {
            $paymentMethod = 'Tarjeta';
        } elseif (preg_match('/transferencia/iu', $combined)) {
            $paymentMethod = 'Transferencia';
        }

        // 3. Platillos detectados
        $items = [];
        if (preg_match('/•\s*\*?Detalle:\*?\s*(.+)/iu', $text, $m)) {
            $items[] = trim($m[1]);
        } else {
            $menuKeywords = [
                'Combo Tizón ($399)'             => '/combo\s*tiz[oó]n/iu',
                'Pizza Tizón Pepperoni ($199)'   => '/(tiz[oó]n\s*pe+p+eroni|pizza\s*(tiz[oó]n\s*)?de\s*pe+p+eroni|una\s*de\s*pe+p+eroni)/iu',
                'Pizza Tizón Especial ($260)'    => '/tiz[oó]n\s*especial|la\s*especial/iu',
                'Pizza Tizón Italiana ($220)'    => '/tiz[oó]n\s*italiana|la\s*italiana/iu',
                'Pizza Tizón Fuego ($220)'       => '/tiz[oó]n\s*fuego|la\s*fuego/iu',
                'Spaguetti con Albóndigas ($65)' => '/(spaguet+i|espagueti)\s*(con\s*alb[oó]ndigas)?/iu',
                'Pan de Ajo Tizón ($99)'         => '/pan\s*de\s*ajo/iu',
            ];
            // Analizar solo las últimas líneas de confirmación para no incluir todo el menú recitado
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            $tailText = implode("\n", array_slice($lines, -8)) . "\n" . $summary;

            foreach ($menuKeywords as $label => $regex) {
                if (preg_match($regex, $tailText)) {
                    // Si es Combo Tizón, no duplicar pepperoni/espagueti/pan de ajo que menciona la descripción del combo
                    if ($label !== 'Combo Tizón ($399)' && in_array('Combo Tizón ($399)', $items, true)) {
                        continue;
                    }
                    $items[] = $label;
                }
            }
        }
        $itemsSummary = !empty($items) ? implode(', ', $items) : ($summary ?: 'Pedido confirmado por IA');

        // 4. Dirección
        $address = null;
        if (preg_match('/•\s*\*?Direcci[oó]n:\*?\s*(.+)/iu', $text, $m)) {
            $address = trim($m[1]);
        } elseif ($deliveryType === 'delivery') {
            if (preg_match('/(calle\s+[^\.\n\?]+colonia\s+[^\.\n\?]+)/iu', $text, $m)) {
                $address = trim($m[1]);
            } elseif (preg_match('/(calle\s+[^\.\n\?]+)/iu', $text, $m)) {
                $address = trim($m[1]);
            }
        } else {
            $address = 'Recoger en sucursal';
        }

        // 5. Total en MXN
        $totalAmount = null;
        if (preg_match('/\$\s*([0-9]{2,4}(?:\.[0-9]{2})?)/u', $combined, $m)) {
            $totalAmount = (float) $m[1];
        } else {
            $wordTotals = [
                'cuatrocientos treinta y nueve' => 439.00,
                'trescientos noventa y nueve'   => 399.00,
                'trescientos treinta y ocho'    => 338.00,
                'trescientos'                   => 300.00,
                'doscientos sesenta'            => 260.00,
                'doscientos treinta y nueve'    => 239.00,
                'doscientos veinte'             => 220.00,
                'ciento noventa y nueve'        => 199.00,
            ];
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            $lastThree = mb_strtolower(implode(' ', array_slice($lines, -4)));
            foreach ($wordTotals as $words => $val) {
                if (str_contains($lastThree, $words)) {
                    $totalAmount = $val;
                    break;
                }
            }
        }

        if ($totalAmount === null) {
            $calc = 0.0;
            foreach ($items as $it) {
                if (preg_match('/\$([0-9]+)/', $it, $pm)) {
                    $calc += (float) $pm[1];
                }
            }
            if ($calc > 0) {
                if ($deliveryType === 'delivery') {
                    $calc += 40.0;
                }
                $totalAmount = $calc;
            }
        }

        return [
            'customer_name'    => null,
            'items_summary'    => $itemsSummary,
            'delivery_type'    => $deliveryType,
            'delivery_address' => $address,
            'payment_method'   => $paymentMethod,
            'total_amount'     => $totalAmount,
        ];
    }

    /**
     * Envía confirmación al cliente y alerta de comanda al administrador/cocina.
     * Si la ventana de 24h de WhatsApp está cerrada, usa automáticamente una Plantilla aprobada.
     */
    public function sendOrderNotifications(
        RestaurantOrder $order,
        ?Tenant $tenant = null,
        bool $notifyCustomer = true,
        bool $notifyKitchen = true
    ): bool {
        try {
            $waAccount = WhatsappAccount::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id)
                ->where('is_active', true)
                ->first()
                ?? WhatsappAccount::withoutGlobalScopes()
                    ->where('is_active', true)
                    ->first();

            if (!$waAccount) {
                return false;
            }

            $tenant ??= Tenant::find($order->tenant_id);
            $restaurantName = $tenant?->name ?: 'Tizón 317 Pizzería';
            $estimatedTime  = $order->delivery_type === 'pickup'
                ? '20 a 25 minutos (Pasar a recoger)'
                : (($tenant->settings['estimated_time'] ?? '35 a 45 minutos') . ' (Domicilio)');

            $totalFormatted = $order->total_amount ? '$' . number_format((float) $order->total_amount, 2) . ' MXN' : 'Por confirmar';
            $modalityLabel  = $order->delivery_type === 'pickup' ? 'Pasar a recoger en sucursal' : 'Envío a domicilio (' . ($order->delivery_address ?: 'Dirección registrada') . ')';
            $shortDetail    = "{$order->items_summary} | Total: {$totalFormatted} ({$order->payment_method}) | {$modalityLabel}";

            $sentAny = false;

            // 1. Notificar al cliente (si tenemos su teléfono y no fue ya notificado por el flujo de chat de WhatsApp)
            if ($notifyCustomer && !empty($order->customer_phone)) {
                $customerText = "🍕 *¡Pedido Confirmado #{$order->order_number} — {$restaurantName}!*\n"
                    . "👤 *Cliente:* {$order->customer_name}\n"
                    . "📋 *Pedido:* {$order->items_summary}\n"
                    . "🛵 *Modalidad:* {$modalityLabel}\n"
                    . "💳 *Pago:* {$order->payment_method}\n"
                    . "💰 *Total:* {$totalFormatted}\n"
                    . "⏱️ *Tiempo estimado:* {$estimatedTime}\n\n"
                    . "¡Tu pedido ya entró a cocina, muchas gracias por tu compra!";

                $sentCustomer = $this->sendSmartWhatsApp(
                    $waAccount,
                    $order->customer_phone,
                    $customerText,
                    'confirmacion_pedido_restaurante',
                    [
                        $order->customer_name ?: 'Cliente',
                        "{$restaurantName} (#{$order->order_number})",
                        $shortDetail,
                        $estimatedTime,
                    ],
                    // Fallback params para plantilla confirmacion_cita (ya aprobada en Meta) si la nueva sigue PENDING
                    [
                        "{$order->customer_name} (Pedido #{$order->order_number} en {$restaurantName})",
                        "Hoy ({$estimatedTime}) — {$shortDetail}",
                    ]
                );
                $sentAny = $sentAny || $sentCustomer;
            }

            // 2. Notificar al WhatsApp de Cocina / Administrador
            $kitchenPhone = $tenant?->kitchenWhatsappPhone();
            if ($notifyKitchen && !empty($kitchenPhone)) {
                $channelName = strtoupper($order->channel) . ' IA';
                $kitchenText = "🛎️ *NUEVA COMANDA #{$order->order_number} ({$channelName})*\n"
                    . "👤 *Cliente:* {$order->customer_name} ({$order->customer_phone})\n"
                    . "📋 *Platillos:* {$order->items_summary}\n"
                    . "🛵 *Entrega:* {$modalityLabel}\n"
                    . "💳 *Pago:* {$order->payment_method}\n"
                    . "💰 *Total:* {$totalFormatted}\n"
                    . "━━━━━━━━━━━━━━━━━━━━\n"
                    . "🖥️ *KDS Cocina:* https://crm.xedik.com/orders/kds";

                $sentKitchen = $this->sendSmartWhatsApp(
                    $waAccount,
                    $kitchenPhone,
                    $kitchenText,
                    'nueva_comanda_cocina',
                    [
                        "{$restaurantName} (#{$order->order_number})",
                        $channelName,
                        "{$order->customer_name} ({$order->customer_phone})",
                        $shortDetail,
                    ],
                    [
                        "COCINA {$restaurantName} — Comanda #{$order->order_number} ({$channelName})",
                        "AHORA — Cliente: {$order->customer_name} ({$order->customer_phone}) | {$shortDetail}",
                    ]
                );
                $sentAny = $sentAny || $sentKitchen;
            }

            return $sentAny;
        } catch (\Throwable $e) {
            Log::warning('[RestaurantOrderService] Error enviando WhatsApp de pedido: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Determina si el destinatario tiene ventana de 24h abierta.
     * - Si está abierta: envía el mensaje de texto enriquecido Y si falla o no está abierta,
     *   envía la Plantilla Oficial de Meta (con fallback automático a `confirmacion_cita`).
     */
    public function sendSmartWhatsApp(
        WhatsappAccount $waAccount,
        string $rawPhone,
        string $freeText,
        string $primaryTemplateName,
        array $primaryTemplateParams,
        array $fallbackCitaParams
    ): bool {
        $digits = preg_replace('/\D+/', '', $rawPhone);
        if (strlen($digits) < 10) {
            return false;
        }
        $last10 = substr($digits, -10);
        // En México Meta Cloud API acepta 521 + 10 dígitos o 52 + 10 dígitos
        $toNumber = strlen($digits) === 10 ? '521' . $digits : $digits;

        $apiBase = rtrim((string) env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v22.0'), '/');

        // Verificar si existe un mensaje entrante de este número en las últimas 23.5 horas
        $windowOpen = Message::withoutGlobalScopes()
            ->where('direction', 'inbound')
            ->where('from_email', 'like', "%{$last10}")
            ->where('sent_at', '>=', now()->subHours(23)->subMinutes(30))
            ->exists();

        if ($windowOpen) {
            $resp = Http::withToken($waAccount->access_token)
                ->timeout(8)
                ->post("{$apiBase}/{$waAccount->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $toNumber,
                    'type'              => 'text',
                    'text'              => ['body' => $freeText],
                ]);

            if ($resp->successful()) {
                Log::info("[RestaurantOrderService] WhatsApp texto libre enviado (ventana 24h abierta) a {$toNumber}");
                return true;
            }
        }

        // Ventana de 24h cerrada (o falló texto libre) -> Enviar Plantilla (Template)
        $tplResp = $this->sendMetaTemplate($apiBase, $waAccount, $toNumber, $primaryTemplateName, 'es_MX', $primaryTemplateParams);
        if ($tplResp) {
            Log::info("[RestaurantOrderService] WhatsApp plantilla '{$primaryTemplateName}' enviada a {$toNumber}");
            return true;
        }

        // Fallback inmediato a la plantilla ya aprobada `confirmacion_cita` mientras Meta aprueba la nueva
        $fallbackResp = $this->sendMetaTemplate($apiBase, $waAccount, $toNumber, 'confirmacion_cita', 'es_MX', $fallbackCitaParams);
        if ($fallbackResp) {
            Log::info("[RestaurantOrderService] WhatsApp plantilla fallback 'confirmacion_cita' enviada a {$toNumber}");
            return true;
        }

        return false;
    }

    private function sendMetaTemplate(
        string $apiBase,
        WhatsappAccount $waAccount,
        string $toNumber,
        string $templateName,
        string $language,
        array $bodyParams
    ): bool {
        $parameters = array_map(fn ($val) => [
            'type' => 'text',
            // Meta no permite saltos de línea ni tabulaciones dentro de parámetros de plantilla
            'text' => mb_substr(preg_replace('/[\r\n\t]+/', ' ', trim((string) $val)), 0, 900),
        ], $bodyParams);

        $resp = Http::withToken($waAccount->access_token)
            ->timeout(8)
            ->post("{$apiBase}/{$waAccount->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'to'                => $toNumber,
                'type'              => 'template',
                'template'          => [
                    'name'       => $templateName,
                    'language'   => ['code' => $language],
                    'components' => [
                        [
                            'type'       => 'body',
                            'parameters' => $parameters,
                        ],
                    ],
                ],
            ]);

        if (!$resp->successful()) {
            Log::warning("[RestaurantOrderService] Template '{$templateName}' failed for {$toNumber}: " . $resp->body());
            return false;
        }

        return true;
    }
}
