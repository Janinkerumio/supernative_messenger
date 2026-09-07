<?php

namespace App\NativeComponents;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * First-launch identity setup. Shown at /welcome until a local user exists;
 * creates that user and drops into Chats. Device registration with the API
 * happens on the next background sync — nothing here touches the network, so
 * the "Get started" tap never hangs.
 */
class Onboarding extends Screen
{
    protected bool $hidesTabBar = true;

    protected bool $hidesNavBar = true;

    public string $name = '';

    public string $username = '';

    public ?string $error = null;

    public function mount(): void
    {
        // Already set up (dev with demo data, or a stale deep link) — skip in.
        if ($this->hasIdentity()) {
            $this->onboardingRedirect = true;
            $this->replace('/');
        }
    }

    public function start(): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $this->name));
        $username = Str::of($this->username)->lower()->replaceMatches('/[^a-z0-9_]+/', '')->toString();

        if (mb_strlen($name) < 2) {
            $this->error = 'Enter your name (at least 2 characters).';

            return;
        }

        if (mb_strlen($username) < 3) {
            $this->error = 'Pick a username — letters, numbers and _ only.';

            return;
        }

        $this->error = null;

        User::create([
            'name' => $name,
            'username' => $username,
            'email' => $username.'@supernative.app',
            'password' => Str::random(40),
            'accent' => '#0A7CFF',
            'is_online' => true,
        ]);

        $this->currentUser = null;   // reset Screen::me() memo
        $this->onboardingRedirect = true;
        $this->replace('/');
    }

    public function render(): View
    {
        return view('native.onboarding', [
            'name' => $this->name,
            'username' => $this->username,
            'error' => $this->error,
            'canSubmit' => trim($this->name) !== '' && trim($this->username) !== '',
        ]);
    }
}
