# SuperNative Messenger — going live

One Laravel codebase, two runtime roles:

| Role | What runs it | Talks to |
|------|--------------|----------|
| **Server** (hosted API) | `php artisan serve` / nginx+fpm / Octane / Laravel Cloud | its own DB, Reverb, FCM |
| **Mobile** (NativePHP Edge app) | `php artisan native:run` on device/emulator | the server over HTTPS via `MESSENGER_API_URL` |

If `MESSENGER_API_URL` is blank the mobile app runs **fully offline** against its local SQLite mirror — every screen still works, it just doesn't sync.

---

## 1. Run the server

```bash
# .env
APP_ROLE=server
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database

php artisan migrate           # demo data self-seeds on first boot (App\Support\DemoWorld)
php artisan serve             # the API + web
php artisan reverb:start      # websockets for real-time delivery
php artisan queue:work        # push-notification fan-out (App\Listeners\SendMessagePush)
```

Endpoints (all under `/api`, bearer token from `POST /auth/register-device`):

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/auth/register-device` | issue a Sanctum token for a device |
| GET  | `/me` · PATCH `/me/settings` | profile + privacy flags + theme |
| GET  | `/contacts` | people you can message |
| GET/POST | `/conversations` | list / find-or-create a direct thread |
| GET  | `/conversations/{id}` | one thread incl. `others_read_through` |
| GET/POST | `/conversations/{id}/messages` | fetch (`?after=<id>`) / send (`client_uuid` = idempotency) |
| POST | `/conversations/{id}/read` | read receipt (no-op if the caller disabled receipts) |
| POST | `/presence` | `{online: bool}` (forced `false` if the caller hid active status) |
| POST | `/devices/token` | register / refresh the FCM / APNs token |

Broadcast events on `private-conversations.{id}` (see `routes/channels.php`):
`message.sent`, `message.read`, `presence.changed`.

---

## 2. Point the mobile build at it

```bash
# .env for the native build
MESSENGER_API_URL=https://your-api.example.com
```

The app registers the local "you" identity on first sync, stores the token in
the native keychain (`SecureStorage`), then keeps the local mirror in step:

- `MessengerApi` — HTTP client; returns `null` on any failure so screens fall back to cache.
- `MessengerSync` — upserts rows **keyed by server id** (server + app both seed from `DemoWorld`, so ids line up).
- `ConvoShow` polls the thread every 4 s (`#[Poll]`) as the delivery path inside the Edge runtime; push notifications cover the backgrounded case.

> Local-dev note: `php artisan serve` on Windows adds ~2–3 s per request (`php -S`, no keep-alive), so live screens feel slow against it. fpm / Octane / Laravel Cloud are sub-100 ms.

---

## 3. Push notifications (FCM HTTP v1)

Inert until configured — `App\Services\Push\FcmSender::enabled()` is `false` and every send is a no-op.

```bash
# .env
FCM_ENABLED=true
FCM_PROJECT_ID=your-firebase-project-id
FCM_CREDENTIALS=/absolute/path/to/service-account.json
```

Flow: device calls `PushNotifications::enroll()` (permission prompt + token) →
`POST /api/devices/token` → on every `MessageSent`, the queued
`SendMessagePush` listener pushes to the other participants' device tokens.
Dead tokens (`UNREGISTERED`) are pruned automatically.

---

## 4. Privacy flags (Settings screen)

Both are enforced **server-side**, symmetric (WhatsApp-style):

- **Show active status** off → you're never reported online, and you don't see anyone else's status.
- **Read receipts** off → your `read` calls are dropped and "Seen" is never shown for you; you also don't see others' receipts.

---

## 5. Docker

A single image (`Dockerfile`) runs the whole server role — nginx + php-fpm +
Reverb + queue worker + scheduler, supervised.

```bash
# build (the --secret keeps nativephp/* GitHub fetches off the anon rate limit)
DOCKER_BUILDKIT=1 docker build --secret id=github_token,env=GITHUB_TOKEN -t supernative .

# run the full stack (app + mysql + redis)
cp .env.docker .env.docker.local          # set APP_KEY + passwords
docker compose --env-file .env.docker.local up --build -d
```

| Port | Service |
|------|---------|
| 8080 | HTTP API (nginx → php-fpm) |
| 8081 | Reverb websockets |

The entrypoint waits for the DB, runs `migrate --force`, then
`config:cache` + `event:cache` (never `route:cache` — `Route::native()`
registers Closure routes). Demo data self-seeds on first boot.

Put nginx (8080) and Reverb (8081) behind your TLS terminator; then set
`REVERB_HOST`, `REVERB_PORT=443`, `REVERB_SCHEME=https` so clients dial the
public address while `REVERB_SERVER_*` stays the in-container bind.

Files: `docker/{nginx,supervisord,php,opcache,php-fpm-pool}.conf`,
`docker/entrypoint.sh`, `.env.docker`, `docker-compose.yml`.

## 6. Dark mode

Follows the OS: every native view carries `dark:` classes and re-renders on
`AppearanceChanged`. The Settings "Appearance" selector stores a
`system | light | dark` preference (synced to `/me/settings`); a forced
light/dark override is applied via `config/nativephp.php` `appearance` at
build time.
