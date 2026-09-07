<?php

namespace App\Services;

use App\Models\Account;
use App\Support\Runtime;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Facades\SecureStorage;
use Throwable;

/**
 * HTTP client for the hosted SuperNative API.
 *
 * When MESSENGER_API_URL is unset the app runs fully offline against its
 * local SQLite mirror — `configured()` is false and every call returns null.
 * Network failures also return null so screens degrade to cached data instead
 * of erroring.
 */
class MessengerApi
{
    /** Legacy single-account key — still read as a fallback for pre-multi-account installs. */
    private const LEGACY_TOKEN_KEY = 'messenger.api_token';

    public function configured(): bool
    {
        return filled($this->baseUrl());
    }

    public function baseUrl(): ?string
    {
        return config('services.messenger.url');
    }

    public function authenticated(): bool
    {
        return $this->configured() && filled($this->token());
    }

    /**
     * Cheap, bounded reachability probe (GET {base}/up — Laravel's health
     * route). Memoised for the life of this instance so a dead server costs
     * ONE ~3s timeout per session, not one per API call. Everything in
     * request() short-circuits on this, which is what keeps a screen's
     * mount() from freezing for 30s+ when the API is down.
     */
    public function online(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        return $this->memo['__online'] ??= (function (): bool {
            try {
                return Http::baseUrl(rtrim($this->baseUrl(), '/'))
                    ->connectTimeout(4)
                    ->timeout(5)
                    ->get('up')
                    ->successful();
            } catch (Throwable) {
                return false;
            }
        })();
    }

    /** Force the next call to re-probe reachability (e.g. on app foreground). */
    public function recheck(): void
    {
        unset($this->memo['__online']);
    }

    // ── Auth ────────────────────────────────────────────────────────────────

    /**
     * @param  array{name:string,username?:string,platform?:string,push_token?:string,device_name?:string}  $identity
     */
    public function registerDevice(array $identity): ?array
    {
        $result = $this->request(fn (PendingRequest $r) => $r->post('auth/register-device', $identity));

        if ($result && isset($result['token'])) {
            $this->setToken($result['token']);
        }

        return $result;
    }

    public function forgetToken(): void
    {
        $this->setToken(null);
    }

    // ── Reads (memoized for the lifetime of this instance — it's a singleton,
    //    so one screen render reuses a single GET; writes bust the cache) ─────

    /** @var array<string, mixed> */
    private array $memo = [];

    public function me(): ?array
    {
        return $this->remember('me', fn () => $this->data(fn (PendingRequest $r) => $r->get('me')));
    }

    public function contacts(): ?array
    {
        return $this->remember('contacts', fn () => $this->collection(fn (PendingRequest $r) => $r->get('contacts')));
    }

    public function conversations(): ?array
    {
        return $this->remember('conversations', fn () => $this->collection(fn (PendingRequest $r) => $r->get('conversations')));
    }

    public function conversation(int $conversationId): ?array
    {
        return $this->remember("conversation:{$conversationId}", fn () => $this->data(
            fn (PendingRequest $r) => $r->get("conversations/{$conversationId}")
        ));
    }

    public function messages(int $conversationId, ?int $after = null): ?array
    {
        return $this->collection(fn (PendingRequest $r) => $r->get(
            "conversations/{$conversationId}/messages",
            array_filter(['after' => $after])
        ));
    }

    /** Drop memoized reads — call after any mutation so the next read is fresh.
     *  The reachability probe (`__online`) is kept; use recheck() for that. */
    public function flush(): void
    {
        $online = $this->memo['__online'] ?? null;
        $this->memo = [];

        if ($online !== null) {
            $this->memo['__online'] = $online;
        }
    }

    private function remember(string $key, callable $fetch): ?array
    {
        return array_key_exists($key, $this->memo)
            ? $this->memo[$key]
            : ($this->memo[$key] = $fetch());
    }

    // ── Writes ──────────────────────────────────────────────────────────────

    public function updateSettings(array $settings): ?array
    {
        $this->flush();

        return $this->data(fn (PendingRequest $r) => $r->patch('me/settings', $settings));
    }

    public function createConversation(int $userId): ?array
    {
        $this->flush();

        return $this->data(fn (PendingRequest $r) => $r->post('conversations', ['user_id' => $userId]));
    }

    public function sendMessage(int $conversationId, string $body, string $clientUuid): ?array
    {
        $this->flush();

        return $this->data(fn (PendingRequest $r) => $r->post(
            "conversations/{$conversationId}/messages",
            ['body' => $body, 'client_uuid' => $clientUuid],
        ));
    }

    public function markRead(int $conversationId): ?array
    {
        return $this->request(fn (PendingRequest $r) => $r->post("conversations/{$conversationId}/read"));
    }

    public function presence(bool $online): ?array
    {
        return $this->request(fn (PendingRequest $r) => $r->post('presence', ['online' => $online]));
    }

    public function registerPushToken(string $pushToken, string $platform = 'unknown'): ?array
    {
        return $this->request(fn (PendingRequest $r) => $r->post('devices/token', [
            'push_token' => $pushToken,
            'platform' => $platform,
        ]));
    }

    // ── Token storage (native keychain, per account, off-device fallback) ───

    /** The storage key for the currently-active account's token. */
    protected function tokenKey(): string
    {
        return Account::current()?->tokenKey() ?? self::LEGACY_TOKEN_KEY;
    }

    public function token(): ?string
    {
        $key = $this->tokenKey();

        // Native keychain is the source of truth on device; the cache mirror
        // keeps it fast and lets dev / tests work without the bridge.
        if (Runtime::onDevice()) {
            $native = SecureStorage::get($key);

            if ($native) {
                return $native;
            }
        }

        $token = Cache::get($key);

        // One-time migration: an install that predates multi-account has its
        // token under the legacy key — adopt it for the current account.
        if (! $token && $key !== self::LEGACY_TOKEN_KEY) {
            $legacy = Runtime::onDevice() ? SecureStorage::get(self::LEGACY_TOKEN_KEY) : null;
            $legacy ??= Cache::get(self::LEGACY_TOKEN_KEY);

            if ($legacy) {
                $this->setToken($legacy);

                return $legacy;
            }
        }

        return $token;
    }

    public function setToken(?string $token): void
    {
        $key = $this->tokenKey();

        if (Runtime::onDevice()) {
            SecureStorage::set($key, $token);
        }

        $token === null
            ? Cache::forget($key)
            : Cache::forever($key, $token);
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/').'/api')
            ->acceptJson()
            ->connectTimeout(4)
            ->timeout(6)
            ->when($this->token(), fn (PendingRequest $r) => $r->withToken($this->token()));
    }

    /** @return array<string, mixed>|null  Raw decoded body, or null on failure. */
    protected function request(callable $call): ?array
    {
        // configured() gates "is there a server?"; online() gates "can we
        // reach it right now?" — without the latter, four dead calls in a
        // row block a screen's mount() for ~30s and the app looks hung.
        if (! $this->configured() || ! $this->online()) {
            return null;
        }

        try {
            $response = $call($this->client());

            if ($response->failed()) {
                Log::warning('MessengerApi request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json() ?? [];
        } catch (Throwable $e) {
            Log::info('MessengerApi unreachable: '.$e->getMessage());

            return null;
        }
    }

    /** Pull the `data` envelope from a resource response. */
    protected function data(callable $call): ?array
    {
        $body = $this->request($call);

        return $body === null ? null : ($body['data'] ?? $body);
    }

    /** Pull the `data` list from a resource collection response. */
    protected function collection(callable $call): ?array
    {
        $body = $this->request($call);

        return $body === null ? null : array_values($body['data'] ?? $body);
    }
}
