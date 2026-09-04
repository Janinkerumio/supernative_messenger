<?php

namespace App\Services;

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
    private const TOKEN_KEY = 'messenger.api_token';

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

    /** Drop memoized reads — call after any mutation so the next read is fresh. */
    public function flush(): void
    {
        $this->memo = [];
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

    // ── Token storage (native keychain, with an off-device fallback) ─────────

    public function token(): ?string
    {
        // Native keychain is the source of truth on device; the cache mirror
        // keeps it fast and lets dev / tests work without the bridge.
        if (Runtime::onDevice()) {
            $native = SecureStorage::get(self::TOKEN_KEY);

            if ($native) {
                return $native;
            }
        }

        return Cache::get(self::TOKEN_KEY);
    }

    protected function setToken(?string $token): void
    {
        if (Runtime::onDevice()) {
            SecureStorage::set(self::TOKEN_KEY, $token);
        }

        $token === null
            ? Cache::forget(self::TOKEN_KEY)
            : Cache::forever(self::TOKEN_KEY, $token);
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/').'/api')
            ->acceptJson()
            ->timeout(8)
            ->when($this->token(), fn (PendingRequest $r) => $r->withToken($this->token()));
    }

    /** @return array<string, mixed>|null  Raw decoded body, or null on failure. */
    protected function request(callable $call): ?array
    {
        if (! $this->configured()) {
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
