<?php

namespace App\NativeComponents;

use App\Models\User;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;

class NewChat extends Screen
{
    protected bool $hidesTabBar = true;

    public string $query = '';

    public function mount(): void
    {
        $this->sync()->syncContacts();
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

    public function render(): View
    {
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
