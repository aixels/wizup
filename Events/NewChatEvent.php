<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class NewChatEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;
    public $organizationId;

    /**
     * Create a new event instance.
     *
     * @param mixed $chat
     * @param int $organizationId
     */
    public function __construct($chat, $organizationId)
    {
        $this->chat = $chat;
        $this->organizationId = $organizationId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn()
    {
        try {
            // Check if Pusher settings are available
            if (config('broadcasting.connections.pusher.key') && config('broadcasting.connections.pusher.secret')) {
                $channel = 'chats.' . 'ch' . $this->organizationId;
                return new Channel($channel);
            } else {
                // Log an error if Pusher settings are not configured
                Log::error('Pusher settings are not configured.');
                return;
            }
        } catch (Exception $e) {
            // Log the exception and prevent the event from broadcasting
            Log::error('Failed to broadcast event: ' . $e->getMessage());
            return;
        }
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return ['chat' => $this->chat];
    }
}
