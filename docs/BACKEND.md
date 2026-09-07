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
- **Instant apply.** `Screen::onInbox()` / `ConvoShow::onRealtime()` first hand
  the `message.sent` broadcast payload straight to
  `MessengerSync::applyRealtimeMessage()` — the row is upserted into the mirror
  and the conversation bumped with **no HTTP round-trip**, so the bubble paints
  the moment the socket delivers. A follow-up pull then reconciles ordering /
  read state (and fetches the thread in full if it was previously unknown).
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

### The native layer — `fatlum/nativephp-push`

Core `nativephp/mobile` ships the *PHP* push API (`PushNotifications` facade,
`TokenGenerated` event, ephemeral background runtime) but **no native FCM/APNs
implementation** — a Firebase plugin has to supply it. We use
[`fatlum/nativephp-push`](https://nativephp.com/plugins/fatlum/nativephp-push),
MIT, **vendored and patched** under `packages/nativephp-push/`:

- `composer.json` — `nativephp/mobile` constraint widened `^3.2` → `^3.2 || ^4.0`
  (upstream hasn't tagged 4.x yet; verified compatible against 4.3.2 — every
  core Kotlin symbol it calls exists with the same signature).
- `nativephp.json` — added `android.gradle_plugins` for the Google Services
  Gradle plugin (core 4.3's template no longer applies it conditionally).
- `resources/google-services.json` — checked in here (Firebase project
  `supernative-messenger`, package `com.janin.supernativemessenger`).

It's wired as a `path` repository in the root `composer.json` and listed in
`NativeServiceProvider::plugins()`. On `native:run` / `native:build` the plugin
compiler pulls in `firebase-messaging`, the `PushMessagingService`,
`POST_NOTIFICATIONS`, the Gradle plugin, and copies `google-services.json` into
the build (`native-push:copy-assets` hook) — **no manual gradle steps**.

`.env` needs `APS_ENVIRONMENT=production` (iOS entitlement; the plugin manifest
requires it as a secret). `NATIVEPHP_PUSH_NOTIFICATIONS=true` is kept as
belt-and-braces. iOS push additionally needs a `GoogleService-Info.plist` and
the `ios.assets` block restored in the fork's manifest — not set up (Android
target).

### Runtime

- `App\Services\PushManager` — enrollment + the permission fallback chain
  (`requestPermissionFlow()`): **enroll → open app settings → native "open
  settings" dialog → on-screen hint**.
- **Token flow.** The plugin fires core's `TokenGenerated` on first enrol and on
  every background refresh (`onNewToken`). `AppServiceProvider` listens for it
  globally (not just on a screen) → `PushManager::sendToken()` → `POST
  /api/devices/token`. `PushManager::syncToken()` on the poll tick is the
  backstop (`PushNotifications::getToken()`).
- The server's `SendMessagePush` listener (queued on `MessageSent`) does the
  actual FCM v1 send to the recipient's `devices.push_token` rows.

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
