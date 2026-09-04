<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorizeParticipant($request->user(), $conversation);

        $messages = $conversation->messages()
            ->with('user')
            ->when($request->integer('after'), fn ($q, $after) => $q->where('id', '>', $after))
            ->oldest()
            ->limit($request->integer('limit', 200))
            ->get();

        return MessageResource::collection($messages);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        // Idempotent on client_uuid so a retried POST returns the same row.
        $message = Message::query()
            ->when($data['client_uuid'] ?? null, fn ($q, $uuid) => $q->where('client_uuid', $uuid))
            ->where('conversation_id', $conversation->id)
            ->first();

        $created = false;

        if (! $message) {
            $message = $conversation->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $data['body'],
                'client_uuid' => $data['client_uuid'] ?? null,
                'delivered_at' => now(),
            ]);
            $conversation->update(['last_message_at' => $message->created_at]);
            $created = true;
        }

        $message->load('user');

        if ($created) {
            broadcast(new MessageSent($message))->toOthers();
        }

        return (new MessageResource($message))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $conversation);

        $me = $request->user();

        // Privacy flag: don't record or broadcast read state when the user has
        // read receipts disabled.
        if (! $me->read_receipts_enabled) {
            return response()->json(['ok' => true, 'receipts' => 'disabled']);
        }

        $at = now();
        $conversation->load('participants');
        $conversation->markReadBy($me, $at);

        broadcast(new MessageRead($conversation, $me, $at->toIso8601String()))->toOthers();

        return response()->json(['ok' => true, 'read_at' => $at->toIso8601String()]);
    }

    protected function authorizeParticipant(User $user, Conversation $conversation): void
    {
        abort_unless(
            $conversation->participants()->whereKey($user->id)->exists(),
            403,
            'Not a participant of this conversation.'
        );
    }
}
