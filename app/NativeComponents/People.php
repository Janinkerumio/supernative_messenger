<?php

namespace App\NativeComponents;

use App\Models\User;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;

class People extends Screen
{
    public string $query = '';

    public function mount(): void
    {
        $this->sync()->syncContacts();
    }

    public function onResume(): void
    {
        $this->sync()->syncContacts();
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()
            ->title('People')
            ->displayMode('large')
            ->searchBar(placeholder: 'Search people', onQuery: 'search', debounceMs: 150);
    }

    public function search(string $text): void
    {
        $this->query = trim($text);
    }

    public function message(int $userId): void
    {
        $other = User::findOrFail($userId);

        $this->navigate('/chats/'.$this->conversationIdWith($other));
    }

    public function render(): View
    {
        $me = $this->me();

        $people = User::query()
            ->whereKeyNot($me->id)
            ->when($this->query !== '', fn ($q) => $q->where('name', 'like', "%{$this->query}%"))
            ->orderByDesc('is_online')
            ->orderBy('name')
            ->get();

        $me = $this->me();
        $visiblePresence = $people->filter(fn (User $u) => $u->showsOnlineDotTo($me));
        $active = $visiblePresence->values();

        return view('native.people', [
            'activeCount' => $active->count(),
            'active' => $active->map(fn (User $u) => $this->row($u, $me))->all(),
            'people' => $people->map(fn (User $u) => $this->row($u, $me))->all(),
        ]);
    }

    protected function row(User $u, User $me): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'tagline' => $u->tagline ?? '@'.$u->username,
            'presence' => $u->presenceLabel($me),
            'initials' => $u->initials(),
            'accent' => $u->accent,
            'online' => $u->showsOnlineDotTo($me),
        ];
    }
}
