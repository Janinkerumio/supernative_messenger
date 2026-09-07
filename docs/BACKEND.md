# Backend & realtime

The API, broadcasting (Reverb) and push sending (FCM) live in a **separate
project**: `../supernative_server` (see its `README.md`).

This project (`supernative_messenger`) is the **NativePHP mobile client**. It
talks to the server over HTTP and keeps a local SQLite mirror.

```dotenv
# .env
MESSENGER_API_URL=https://your-api.example.com   # blank = fully offline

# Realtime — Vibe websocket client → the server's Reverb
PUSHER_APP_KEY=<same as the server's REVERB_APP_KEY>
PUSHER_HOST=<websocket host>
PUSHER_PORT=443
PUSHER_SCHEME=https
VIBE_AUTH_ENDPOINT="${MESSENGER_API_URL}/api/broadcasting/auth"
```

## Realtime (`nativephp/mobile-vibe`)

- Registered in `app/Providers/NativeServiceProvider::plugins()`.
- `AppServiceProvider` wires `Vibe::resolveTokenUsing(...)` to the **current
  account's** bearer token, so private-channel auth (`POST
  /api/broadcasting/auth`, `auth:sanctum`) works and survives an account switch.
- `ConvoShow` subscribes to `private-conversations.{id}` and refetches on
  `message.sent` / `message.read` / reconnect. `ConvoList` subscribes to the
  threads it knows about. `#[Poll]` remains as the fallback when the socket
  isn't connected.

## Accounts (multi-account / switch)

- `App\Models\Account` — one row per identity signed in on this device,
  exactly one `is_current`. The Sanctum token is stored per-account
  (`messenger.api_token.<username>` in SecureStorage / cache).
- Settings → **Accounts**: switch, "Add account" (→ onboarding), "Sign out".
- Switching swaps the token, wipes the conversation mirror, and re-syncs.

## Push (FCM)

- `resources/google-services.json` supplies the Android Firebase config.
- `App\Services\PushManager` — enrollment + the permission fallback chain:
  **enroll → open app settings → native "open settings" dialog → on-screen
  hint**. The device token is POSTed to `/api/devices/token`; the server's
  `SendMessagePush` listener does the actual FCM send.
- Auto-requested once, quietly, on the first `ConvoList` tick after onboarding.
