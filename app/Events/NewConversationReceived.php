<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class NewConversationReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly Conversation $conversation
    ) {}

    /**
     * Canal privado del agente asignado.
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
     * Payload mínimo — solo lo que el JS necesita para renderizar
     * la conv en la lista lateral y en el dropdown de notificaciones.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $preview = Str::limit(
            strip_tags($this->conversation->messages()->latest('sent_at')->value('body_text') ?? ''),
            80
        );

        return [
            'id'         => $this->conversation->id,
            'from_name'  => $this->conversation->from_name,
            'from_email' => $this->conversation->from_email,
            'subject'    => $this->conversation->subject,
            'wait_since' => $this->conversation->wait_since?->diffForHumans(),
            'preview'    => $preview,
        ];
    }

    public function broadcastAs(): string
    {
        return 'NewConversationReceived';
    }
}
