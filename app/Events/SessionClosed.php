<?php

namespace App\Events;

use App\Models\CashRegisterSession;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionClosed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * El usuario que cerró la sesión.
     */
    public User $closingUser;

    /**
     * La sesión que fue cerrada.
     */
    public CashRegisterSession $session;

    /**
     * Crea una nueva instancia del evento.
     */
    public function __construct(CashRegisterSession $session, User $closingUser)
    {
        $this->session = $session;
        $this->closingUser = $closingUser;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Este es un canal privado. Solo los usuarios en esta sesión podrán escuchar.
        return [
            new PrivateChannel('cash-register-session.' . $this->session->id),
        ];
    }

    /**
     * El nombre del evento que se enviará.
     */
    public function broadcastAs(): string
    {
        return 'session.closed';
    }

    /**
     * Los datos que se enviarán con el evento.
     */
    public function broadcastWith(): array
    {
        return [
            'closingUserName' => $this->closingUser->name,
            'closedSessionId' => $this->session->id,
            'cashRegisterId' => $this->session->cash_register_id,
            'originalOpenerId' => $this->session->user_id, // El ID del usuario que ABRIÓ la sesión
        ];
    }
}