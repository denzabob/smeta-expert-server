<?php

namespace App\Policies;

use App\Models\Chat\ChatConversation;
use App\Models\User;

class ChatConversationPolicy
{
    /**
     * User can only view their own support conversation.
     */
    public function view(User $user, ChatConversation $conversation): bool
    {
        return $conversation->created_by_user_id === $user->id;
    }

    /**
     * User can only send messages to their own conversation.
     */
    public function sendMessage(User $user, ChatConversation $conversation): bool
    {
        return $conversation->created_by_user_id === $user->id;
    }

    /**
     * User can only mark their own conversation as read.
     */
    public function markRead(User $user, ChatConversation $conversation): bool
    {
        return $conversation->created_by_user_id === $user->id;
    }
}
