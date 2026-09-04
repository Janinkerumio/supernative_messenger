<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
| Private per-conversation channel. Only participants may subscribe; this is
| where MessageSent / MessageRead / PresenceChanged are broadcast.
*/
Broadcast::channel('conversations.{conversation}', function ($user, Conversation $conversation) {
    return $conversation->participants()->whereKey($user->id)->exists();
});
