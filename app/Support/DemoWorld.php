<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Seeds the SuperNative messenger demo world.
 *
 * The mobile runtime ships an empty (migrated) SQLite database, so there is
 * no `db:seed` step on device. This runs once on first boot instead — and
 * doubles as the DatabaseSeeder body for local development.
 */
class DemoWorld
{
    protected static bool $ran = false;

    /**
     * Whether the demo world should be seeded at all.
     *
     * Off in production — a shipped app starts empty and the Onboarding
     * screen creates the one real "me" user. `SEED_DEMO_WORLD` overrides
     * the default in either direction (e.g. set it false locally to test
     * the onboarding flow).
     */
    public static function enabled(): bool
    {
        return filter_var(
            env('SEED_DEMO_WORLD', ! app()->isProduction()),
            FILTER_VALIDATE_BOOL,
        );
    }

    /** Run the seed regardless of the once-per-process guard (for db:seed). */
    public static function reseed(): void
    {
        static::$ran = false;
        static::ensure();
    }

    /** Create the demo world if it isn't there yet. Safe to call on every boot. */
    public static function ensure(): void
    {
        if (static::$ran || ! static::enabled()) {
            static::$ran = true;

            return;
        }

        try {
            if (! Schema::hasTable('users') || User::query()->exists()) {
                static::$ran = true;

                return;
            }
        } catch (Throwable) {
            // Database not ready (e.g. mid-migration) — nothing to do.
            return;
        }

        static::$ran = true;

        try {
            DB::transaction(static fn () => static::build());
        } catch (Throwable $e) {
            // Never let a seeding failure escape boot() — that would be an
            // unrecoverable crash on launch with no error screen. Log it and
            // carry on; the app will surface a normal "no data" state.
            Log::error('DemoWorld seed failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    protected static function build(): void
    {
        // One bcrypt pass, reused — 9 hashes at BCRYPT_ROUNDS=12 is seconds
        // of dead time on a phone during first launch.
        $password = Hash::make('password');

        $me = User::create([
            'name' => 'Jordan Rivera',
            'username' => 'jordan',
            'email' => 'you@supernative.app',
            'password' => $password,
            'tagline' => 'Building things with PHP on mobile',
            'accent' => '#0A7CFF',
            'is_online' => true,
        ]);

        // The active local account points at Jordan (server id == local id here).
        Account::create([
            'username' => $me->username,
            'name' => $me->name,
            'accent' => $me->accent,
            'server_id' => $me->id,
            'is_current' => true,
            'last_used_at' => now(),
        ]);

        $people = collect([
            ['name' => 'Maya Chen',    'accent' => '#FF2D9B', 'online' => true,  'tagline' => 'Design systems & coffee'],
            ['name' => 'Diego Santos', 'accent' => '#7C4DFF', 'online' => true,  'tagline' => 'Backend, buses, bikes'],
            ['name' => 'Priya Nair',   'accent' => '#00BFA6', 'online' => false, 'tagline' => 'PM · dog person'],
            ['name' => 'Sam Okafor',   'accent' => '#FF6D00', 'online' => false, 'tagline' => 'iOS + guitar'],
            ['name' => 'Lena Vogel',   'accent' => '#2962FF', 'online' => true,  'tagline' => 'QA wizard'],
            ['name' => 'Tomas Hart',   'accent' => '#00C853', 'online' => false, 'tagline' => 'DevRel'],
            ['name' => 'Aisha Bello',  'accent' => '#D500F9', 'online' => false, 'tagline' => 'Data & charts'],
        ])->map(fn ($p, $i) => User::create([
            'name' => $p['name'],
            'username' => strtolower(explode(' ', $p['name'])[0]),
            'email' => strtolower(explode(' ', $p['name'])[0]).'@supernative.app',
            'password' => $password,
            'accent' => $p['accent'],
            'tagline' => $p['tagline'],
            'is_online' => $p['online'],
            'last_seen_at' => $p['online'] ? now() : now()->subMinutes(15 + $i * 37),
        ]));

        $scripts = [
            'Maya Chen' => [
                ['them', 'Did you see the new Edge components?'],
                ['me', 'Just wired up the tab bar — feels native 🔥'],
                ['them', 'The Liquid Glass top bar is *chef’s kiss*'],
                ['me', 'Sending you a build tonight'],
                ['them', 'yesss'],
            ],
            'Diego Santos' => [
                ['them', 'standup in 5'],
                ['me', 'joining now'],
                ['them', 'bring the migration notes'],
            ],
            'Priya Nair' => [
                ['me', 'Roadmap draft is in the doc'],
                ['them', 'Looking now — thanks Jordan'],
                ['them', 'Can we move search to this sprint?'],
            ],
            'Lena Vogel' => [
                ['them', 'Found an edge case in the composer'],
                ['me', 'Repro steps?'],
                ['them', 'Empty message + fast double tap on send'],
                ['me', 'On it 🙏'],
            ],
            'Sam Okafor' => [
                ['them', 'Guitar night friday?'],
                ['me', 'always'],
            ],
        ];

        foreach ($scripts as $name => $lines) {
            $friend = $people->firstWhere('name', $name);
            $convo = Conversation::create(['is_group' => false]);
            $convo->participants()->attach([$me->id, $friend->id]);

            $t = Carbon::now()->subHours(count($lines) + 2);
            foreach ($lines as [$who, $body]) {
                $t = $t->copy()->addMinutes(random_int(3, 40));
                static::messageAt($convo, $who === 'me' ? $me->id : $friend->id, $body, $t);
            }
            $convo->update(['last_message_at' => $t]);
        }

        $group = Conversation::create(['name' => 'Mobile Guild', 'is_group' => true]);
        $group->participants()->attach(
            $people->take(5)->pluck('id')->push($me->id)->all()
        );
        $t = Carbon::now()->subHours(3);
        foreach ([
            [$people[0]->id, 'Ship it or polish it one more day?'],
            [$people[1]->id, 'ship. we iterate on main'],
            [$me->id, 'Cutting the release branch now'],
            [$people[4]->id, 'smoke tests green ✅'],
        ] as [$uid, $body]) {
            $t = $t->copy()->addMinutes(random_int(4, 25));
            static::messageAt($group, $uid, $body, $t);
        }
        $group->update(['last_message_at' => $t]);
    }

    protected static function messageAt(Conversation $convo, int $userId, string $body, Carbon $at): void
    {
        $message = Message::create([
            'conversation_id' => $convo->id,
            'user_id' => $userId,
            'body' => $body,
        ]);

        // created_at/updated_at aren't fillable — backdate them explicitly so
        // threads read in the right order.
        $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }
}
