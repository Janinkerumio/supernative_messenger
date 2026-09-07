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
- **App-wide inbox channel.** Every screen calls `Screen::watchInbox()`, which
  subscribes to `private-users.{serverId}` — the server (`MessageSent` /
  `MessageRead::broadcastOn()`) fans every message event for *all* of the
  account's conversations onto it. Vibe refcounts channels natively, so
  whichever screen is mounted keeps that one socket alive: messages arrive
  live on any tab, and brand-new threads show up without a remount.
  `Screen::onInbox()` (overridable) pulls the conversation list on each event.
- `ConvoShow` *also* subscribes to `private-conversations.{id}` for the open
  thread (force-pull + mark-read + presence). `#[Poll]` remains the fallback
  when the socket isn't connected.
- The inbox subscription is re-read from `Account::current()->server_id` on
  every mount, so an account switch (which remounts to `/`) re-points it; the
  old channel is unsubscribed on unmount.

## Accounts (multi-account / switch)

- `App\Models\Account` — one row per identity signed in on this device,
  exactly one `is_current`. The Sanctum token is stored per-account
  (`messenger.api_token.<username>` in SecureStorage / cache).
- Settings → **Accounts**: switch, "Add account" (→ onboarding), "Sign out".
- Switching swaps the token, wipes the conversation mirror, and re-syncs.

## Push (FCM)

### One-time Android wiring

NativePHP core only installs the Firebase Gradle plugin on behalf of a
*Firebase plugin* — this app has none, so two extra steps are needed:

1. **`NATIVEPHP_PUSH_NOTIFICATIONS=true`** in `.env` — makes `native:run` /
   `native:build` add `<uses-permission POST_NOTIFICATIONS>` to the manifest
   (Android 13+ then shows a Notifications entry for the app and
   `enroll()` can request it) and the aps-environment entitlement on iOS.

2. **`php artisan messenger:android-push`** — run it after **every**
   `native:install` and before `native:run` / `native:build`. It's idempotent
   and:
   - copies `resources/google-services.json` → `nativephp/android/app/`
   - applies `com.google.gms.google-services` in the Gradle files
   - adds `firebase-bom` + `firebase-messaging`
   - belt-and-braces adds `POST_NOTIFICATIONS` to the manifest

`resources/google-services.json` (Firebase console → project settings →
your Android app, package `com.janin.supernativemessenger`) is required and
gitignored.

### Runtime

- `App\Services\PushManager` — enrollment + the permission fallback chain
  (`requestPermissionFlow()`): **enroll → open app settings → native "open
  settings" dialog → on-screen hint**. The device token is POSTed to
  `/api/devices/token`; the server's `SendMessagePush` listener does the
  actual FCM send.

### Priming (pre-permission explainer)

Ported from `textbitz_gate`'s `usePushPriming.js` / `NotificationOptInModal`:

- `PushManager::shouldPrime()` is true **once per launch**, only while the
  decision is still open — on device, no stored decision, permission
  `not_determined`.
- `ConvoList` polls it (`pushPrimerTick`, 2.5 s) and raises a
  `<bottom-sheet>` explainer. **"Turn on notifications"** →
  `markPrimeAccepted()` + `requestPermissionFlow()` (same chain as Settings);
  **"Not now"** → `dismissPrime()` (never returns); backdrop dismiss just
  hides (can reappear next launch).
- The decision key lives in `Cache` (`push.prime_decision`) and is cleared by
  `PushManager::reset()` on sign-out / account switch, so each account is
  primed once.
- The Settings → Notifications **Turn on** button also calls
  `markPrimeAccepted()`, so using it pre-empts the sheet.
