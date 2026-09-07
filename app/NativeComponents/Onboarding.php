<?php

namespace App\NativeComponents;

use App\Models\Account;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Identity setup — first launch (`/welcome` is where the tab screens bounce
 * when no account exists) and "Add account" from Settings. Creates a local
 * {@see Account}, makes it current, and drops into Chats. Registration with
 * the API is bounded (the online() probe) so "Get started" can't hang.
 */
class Onboarding extends Screen
{
    protected bool $hidesTabBar = true;

    protected bool $hidesNavBar = true;

    public string $name = '';

    public string $username = '';

    public ?string $error = null;

    /** The form always renders — no auto-redirect (this is also "Add account"). */

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

        // Re-adding an account you've used before on this device: just switch.
        if ($existing = Account::query()->where('username', $username)->first()) {
            $this->sync()->switchTo($existing);
            $this->finish();

            return;
        }

        $account = Account::create([
            'username' => $username,
            'name' => $name,
            'accent' => '#0A7CFF',
        ]);

        $wasFirst = Account::query()->count() === 1;
        $account->makeCurrent();

        if (! $wasFirst) {
            // Adding alongside another account — clear that one's threads.
            $this->sync()->wipeMirror();
        }

        // Bounded: online() probe caps this; server_id fills in on the next
        // background poll if we're offline right now.
        $this->sync()->ensureRegistered();

        $this->finish();
    }

    protected function finish(): void
    {
        $this->forgetMe();
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
            'hasAccounts' => Account::query()->exists(),
        ]);
    }
}
