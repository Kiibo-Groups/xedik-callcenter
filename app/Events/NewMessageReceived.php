<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class NewMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly Message      $message,
        public readonly Conversation $conversation,
        public readonly ?string      $mediaType = null,
        public readonly ?string      $mediaUrl  = null,
    ) {}

    /**
     * Canal privado del agente asignado a la conversación.
     *
     * @return array<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('agent.' . $this->conversation->assigned_to),
        ];
    }

    /**
     * Identifica la conv y el mensaje para que el JS pueda agregarlo al thread
     * si está abierto. El frontend compara conversation_id/message_id con
     * String(...) === String(...) en vez de depender de que Eloquent hidrate
     * siempre el mismo tipo (PDO puede devolver BIGINT como string en algunos
     * SELECTs) — ver el handler de Pusher en console/index.blade.php.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $tenantTimezone = \App\Helpers\MessageTimePresenter::resolveTenantTimezone($this->conversation->tenant);

        return [
            'conversation_id' => $this->conversation->id,
            'message_id'      => $this->message->id,
            'direction'       => $this->message->direction,
            'channel'         => $this->conversation->channel,
            'from_name'       => $this->message->from_name,
            'from_email'      => $this->message->from_email,
            'subject'         => $this->conversation->subject,
            'body_text'       => $this->message->body_text,
            'preview'         => Str::limit(strip_tags($this->message->body_text ?? ''), 80),
            // sent_at_iso/time/date/day/timezone: mismo formato que carga inicial y
            // refetch — ver MessageTimePresenter. Nunca un string pre-formateado en
            // hora local ni una fecha sin designador de timezone.
            ...\App\Helpers\MessageTimePresenter::present($this->message->sent_at, $tenantTimezone),
            'media_type'      => $this->mediaType,
            'media_url'       => $this->mediaUrl,
        ];
    }

    public function broadcastAs(): string
    {
        return 'NewMessageReceived';
    }
}
