<?php

namespace App\NativeComponents;

use App\Models\User;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;

class NewChat extends Screen
{
    protected bool $hidesTabBar = true;

    public string $query = '';

    public function mount(): void
    {
        $this->requireOnboarding();
    }

    // Contacts are already in the local mirror (ConvoList/People synced them).
    // Refresh in the background only.
    #[Poll(8000)]
    public function live(): void
    {
        if ($this->hasIdentity()) {
            $this->sync()->syncContacts();
        }
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()
            ->title('New message')
            ->displayMode('inline')
            ->back()
            ->searchBar(placeholder: 'To:', onQuery: 'search', debounceMs: 120);
    }

    public function search(string $text): void
    {
        $this->query = trim($text);
    }

    public function startWith(int $userId): void
    {
        $other = User::findOrFail($userId);

        $this->replace('/chats/'.$this->conversationIdWith($other));
    }

    public function render(): View|Element
    {
        if ($this->onboardingRedirect) {
            return $this->blankScreen();
        }

        $me = $this->me();

        $people = User::query()
            ->whereKeyNot($me->id)
            ->when($this->query !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$this->query}%")
                    ->orWhere('username', 'like', "%{$this->query}%")
            ))
            ->orderByDesc('is_online')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'handle' => '@'.($u->username ?? str($u->name)->slug()),
                'initials' => $u->initials(),
                'accent' => $u->accent,
                'online' => (bool) $u->is_online,
            ])
            ->all();

        return view('native.new-chat', [
            'people' => $people,
            'empty' => $people === [],
        ]);
    }
}
