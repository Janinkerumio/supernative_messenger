<?php

namespace App\Services\Push;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal Firebase Cloud Messaging HTTP v1 sender.
 *
 * No SDK: it mints a short-lived OAuth access token from the service-account
 * JSON (RS256 JWT → token endpoint) and POSTs to the v1 send endpoint.
 *
 * Completely inert until FCM_ENABLED=true and FCM_PROJECT_ID / FCM_CREDENTIALS
 * are set — `enabled()` is false and every send() is a no-op that returns 0.
 */
class FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public function enabled(): bool
    {
        return (bool) config('services.fcm.enabled')
            && filled(config('services.fcm.project_id'))
            && is_string(config('services.fcm.credentials'))
            && is_file(config('services.fcm.credentials'));
    }

    /**
     * Send one notification to many device tokens.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data  string-only data payload
     * @return int  number of tokens accepted by FCM
     */
    public function send(array $tokens, string $title, string $body, array $data = []): int
    {
        $tokens = array_values(array_filter(array_unique($tokens)));

        if (! $this->enabled() || $tokens === []) {
            return 0;
        }

        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            Log::warning('FCM: could not obtain access token: '.$e->getMessage());

            return 0;
        }

        $projectId = config('services.fcm.project_id');
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $sent = 0;

        foreach ($tokens as $token) {
            $message = [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'high'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($endpoint, $message);

            if ($response->successful()) {
                $sent++;

                continue;
            }

            // 404 / UNREGISTERED → token is dead; prune it.
            if (in_array($response->status(), [400, 404], true)
                && str_contains($response->body(), 'UNREGISTERED')) {
                \App\Models\Device::where('push_token', $token)->delete();
            } else {
                Log::warning('FCM send failed', ['status' => $response->status(), 'body' => $response->body()]);
            }
        }

        return $sent;
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', now()->addMinutes(50), function () {
            $credentials = json_decode((string) file_get_contents(config('services.fcm.credentials')), true);

            if (! isset($credentials['client_email'], $credentials['private_key'])) {
                throw new RuntimeException('Invalid FCM service-account JSON.');
            }

            $now = time();
            $jwt = JWT::encode([
                'iss' => $credentials['client_email'],
                'sub' => $credentials['client_email'],
                'aud' => self::TOKEN_URI,
                'iat' => $now,
                'exp' => $now + 3600,
                'scope' => self::SCOPE,
            ], $credentials['private_key'], 'RS256');

            $response = Http::asForm()->post(self::TOKEN_URI, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->throw()->json();

            return $response['access_token'];
        });
    }
}
