<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $me = $request->user();

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->whereKey($me->id))
            ->with(['participants', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->orderByDesc('last_message_at')
            ->get();

        return ConversationResource::collection($conversations);
    }

    public function store(Request $request): ConversationResource
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', 'different:'.$request->user()->id],
        ]);

        $me = $request->user();
        $other = User::findOrFail($data['user_id']);

        $conversation = Conversation::query()
            ->where('is_group', false)
            ->whereHas('participants', fn ($q) => $q->whereKey($me->id))
            ->whereHas('participants', fn ($q) => $q->whereKey($other->id))
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 2);

        if (! $conversation) {
            $conversation = Conversation::create(['is_group' => false]);
            $conversation->participants()->attach([$me->id, $other->id]);
        }

        return new ConversationResource($conversation->load('participants'));
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorizeParticipant($request->user(), $conversation);

        return new ConversationResource($conversation->load(['participants', 'messages' => fn ($q) => $q->latest()->limit(1)]));
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
