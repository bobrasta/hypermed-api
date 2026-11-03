# hypermed/messaging

Real-time messaging for the HyperMed Management System as a self-contained Laravel package:
direct and group conversations, text/file/system/entity messages, read pointers and unread
counts, attachments with thumbnails and signed downloads, Reverb (Pusher protocol)
broadcasting, and FCM push. It is a JSON API only. The Flutter package
`hypermed_messaging` is the UI.

- Laravel **11, 12 or 13** (`laravel/framework ^11.0|^12.0|^13.0`). The package uses only Laravel 11 APIs.
  The test suite is run on Laravel 13 (harness) against MariaDB and SQLite.
- PHP 8.2+, `ext-openssl`. `ext-gd` is optional and only needed for image thumbnails.
- MySQL 8 / MariaDB 10.3+ (the conversation list uses `ROW_NUMBER()`).
- Redis is **not** required. It works with any cache and queue driver.

The package never touches host code. Everything it needs from the host comes from
**config, container bindings (interfaces) and Gates**.

---

## 1. Installation into a host app

### Composer path repository (monorepo / copy of the folder)

```jsonc
// host composer.json
"repositories": [
    { "type": "path", "url": "packages/hypermed/messaging", "options": { "symlink": true } }
],
"require": {
    "hypermed/messaging": "*@dev"
}
```

### Composer VCS repository (separate git repo)

```jsonc
"repositories": [
    { "type": "vcs", "url": "git@github.com:your-org/hypermed-messaging.git" }
],
"require": {
    "hypermed/messaging": "dev-main"
}
```

Then:

```bash
composer update hypermed/messaging
php artisan migrate                      # msg_* tables are loaded from the package
php artisan vendor:publish --tag=messaging-config   # optional: config/messaging.php
```

Both providers are auto-discovered: `Hypermed\Messaging\MessagingServiceProvider` and
`Hypermed\Messaging\Calls\CallsServiceProvider`. To copy the migrations into the host
instead, use `--tag=messaging-migrations`.

### Host prerequisites

1. **Sanctum** (or another guard): every API route uses `config('messaging.middleware')`,
   which defaults to `['api', 'auth:sanctum']`.
2. **Broadcasting with Reverb**: install `laravel/reverb` and expose the channel auth endpoint
   with bearer-token auth. With Laravel 11+ `bootstrap/app.php`:

   ```php
   ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['auth:sanctum']])
   ```

   The package registers its own channel callbacks (`routes/channels.php` of the package)
   from its service provider, so you don't need to add anything to the host channels file.
3. A **queue worker** for push jobs (`php artisan queue:work`). Socket broadcasts never
   use the queue.

---

## 2. Configuration & `.env` keys

`config/messaging.php` (publish to change non-env options):

| Key | Env | Default | Meaning |
|---|---|---|---|
| `user_model` | `MESSAGING_USER_MODEL` | `App\Models\User` | Eloquent user model. Users are referenced by `user_id` only. |
| `user_name_column` | `MESSAGING_USER_NAME_COLUMN` | `name` | Display name column. It must be a real column because it is read with a JOIN. |
| `user_avatar_column` | `MESSAGING_USER_AVATAR_COLUMN` | `null` | Column with an absolute avatar URL, or null. |
| `user_search_columns` | | `['name','email']` | Columns searched by the default `UserDirectory`. |
| `route_prefix` | `MESSAGING_ROUTE_PREFIX` | `api/messaging` | |
| `middleware` | | `['api','auth:sanctum']` | |
| `register_routes` / `register_channels` | | `true` | Set false to register them yourself. |
| `default_gates` | | see §3 | Results of the fallback gates. |
| `rate_limit.send_per_minute` | `MESSAGING_SEND_RATE_LIMIT` | `30` | Messages and attachments per user per minute (429 `rate_limited`). |
| `list.*`, `messages.*`, `groups.*` | | | Page sizes, body/preview length, group limits. |
| `attachments.disk` | `MESSAGING_ATTACHMENTS_DISK` | `local` | Any filesystem disk. Production: S3-compatible (Cloudflare R2). |
| `attachments.directory` | `MESSAGING_ATTACHMENTS_DIRECTORY` | `messaging` | |
| `attachments.max_size_kb` | `MESSAGING_ATTACHMENTS_MAX_KB` | `20480` | |
| `attachments.signed_url_ttl` | `MESSAGING_SIGNED_URL_TTL` | `10` (min) | Lifetime of download links. |
| `attachments.use_disk_temporary_urls` | `MESSAGING_ATTACHMENTS_DISK_TEMP_URLS` | `true` | On S3/R2 disks, redirect to the disk's own pre-signed URL. |
| `push.driver` | `MESSAGING_PUSH_DRIVER` | `null` | `null`, `fcm`, or a class implementing `PushNotifier`. |
| `push.connection` / `push.queue` | `MESSAGING_PUSH_QUEUE_CONNECTION` / `MESSAGING_PUSH_QUEUE` | default / `default` | Where `SendPushJob` runs. |
| `push.fcm.credentials` | `MESSAGING_FCM_CREDENTIALS` | | **Absolute path** to the Firebase service-account JSON. Keep it outside the repo. |
| `push.fcm.project_id` | `MESSAGING_FCM_PROJECT_ID` | from JSON | |
| `presence.checker` | `MESSAGING_PRESENCE_CHECKER` | `broadcaster` | `broadcaster` asks Reverb's HTTP API who is in `presence-messaging.presence`. `null` treats everyone as offline. A class name is also accepted. |

The standard Reverb keys (`BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`,
`REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`) are needed as well.
`backend/.env.example` documents every key. Real values go only in `.env`, which is gitignored.

---

## 3. Gates to define

Authorization uses Laravel Gates only. The package registers a fallback for each ability **only
if the host has not defined it**. This check runs after all providers have booted, so a definition
in the host's `AppServiceProvider` always wins.

| Ability | Arguments | Checked when | Package default |
|---|---|---|---|
| `messaging.send` | `Conversation $conversation` | sending messages and attachments | `true` |
| `messaging.group.create` | – | `POST /conversations/group` | `true` |
| `messaging.call` | (see Calls) | starting or joining calls | `true` |
| `messaging.broadcast.org_unit` | reserved (department broadcasts, phase 2) | – | **`false`** |
| `messaging.audit.view` | reserved (audit access, phase 2) | – | **`false`** |

Privileged abilities default to `false`, so forgetting to define them never grants access.
For HyperMed's RBAC, map them to permission strings:

```php
Gate::define('messaging.send', fn (User $u) => $u->hasPermission('messaging.send'));
```

Independently of gates, **every** conversation- or message-scoped endpoint and every channel
requires active participation (`403 {"code":"not_participant"}`).

---

## 4. Interfaces to bind

Bind your implementations in the host's `AppServiceProvider::register()`. They override the
package defaults.

| Interface (`Hypermed\Messaging\Contracts\…`) | Default | Purpose |
|---|---|---|
| `OrgUnitResolver` — `membersOf(string $key): int[]`, `unitsFor(int $userId): string[]` | `NullOrgUnitResolver` | Departments/org units (department channels in phase 2). |
| `EntityLinkResolver` — `resolve(string $type, int $id): ?array{title, subtitle, url, status}`, `canView(int $userId, string $type, int $id): bool` | `NullEntityLinkResolver` (nothing resolvable or shareable) | Entity cards (`type=entity_ref`). `canView` is enforced when a user posts a reference. |
| `UserDirectory` — `search(?string $term, int $limit, ?int $exclude): UserSummary[]` | `EloquentUserDirectory` | `GET /users` (new-chat picker). |
| `PushNotifier` — `send(int[] $userIds, array<string,string> $data): void` | by `push.driver` | FCM HTTP v1 (`FcmPushNotifier`) or `NullPushNotifier`. |
| `PresenceChecker` — `isOnline(int $userId): bool` | by `presence.checker` | Push only goes to users who are offline. |

```php
$this->app->singleton(OrgUnitResolver::class, HyperMedOrgUnitResolver::class);
$this->app->singleton(EntityLinkResolver::class, HyperMedEntityLinkResolver::class);
```

### Using messaging from other modules

```php
use Hypermed\Messaging\Services\MessagingService;

$messaging = app(MessagingService::class);
$messaging->sendSystemMessage($conversationId, 'Approval #42 was approved', ['approval_id' => 42]);
$messaging->postEntityMessage($conversationId, 'approval', 42, senderId: null, body: 'Please review');
```

Both calls broadcast and push like normal messages, and both are safe to call inside your own
DB transaction. Nothing is broadcast unless the transaction commits.

---

## 5. API summary

All routes are under `/api/messaging` with `Authorization: Bearer <token>`. The authoritative
shapes are in `docs/CONTRACT.md`.
Envelope: `{"data": …, "meta": …}`. Errors: `{"message", "code", "errors"?}`, where `code` is one of
`not_participant`, `forbidden`, `not_found`, `validation_failed` or `rate_limited`.

| Method | Path | Notes |
|---|---|---|
| GET | `/conversations?cursor&limit` | Sorted by `last_activity_at desc, id desc`. Cursor-paginated. **≤ 3 SQL queries.** |
| POST | `/conversations/direct` `{user_id}` | Find or create (201/200). Unique per pair (`direct_key` "12:57"). |
| POST | `/conversations/group` `{title, user_ids[]}` | Gate `messaging.group.create`. The creator becomes admin. |
| GET | `/conversations/{id}` | Details with all participants. |
| POST | `/conversations/{id}/mute` `{muted_until\|null}` | Mutes push for this conversation. |
| POST | `/conversations/{id}/participants` `{user_ids[]}` | Group admins only. |
| DELETE | `/conversations/{id}/participants/{userId}` | Leave (self) or remove (admin). |
| GET | `/conversations/{id}/messages?before\|after&limit` | No cursor or `before`: newest first. `after`: ascending catch-up. `meta.has_more`. |
| POST | `/conversations/{id}/messages` | `{client_uuid, type: text\|entity_ref, body, reply_to_id?, meta?}`. Idempotent on `client_uuid`. Rate limited. |
| POST | `/conversations/{id}/attachments` | Multipart `file`, `client_uuid`, `body?`, `reply_to_id?`. |
| PATCH / DELETE | `/messages/{id}` | Edit own text message / soft-delete own message. |
| POST | `/conversations/{id}/read` `{last_read_message_id}` | The pointer never moves backwards. It also raises the caller's delivered pointer to at least the read id. |
| POST | `/conversations/{id}/delivered` `{last_delivered_message_id}` | Returns `{conversation_id, user_id, last_delivered_message_id}`. Clamped to the newest message; never moves backwards. Broadcasts `delivered.updated` only when it advances. Clients debounce (~1 s per conversation). |
| GET | `/unread-summary` | One grouped query. |
| GET | `/users?search&limit` | Excludes the caller. |
| POST / DELETE | `/devices` | FCM/APNs tokens. |
| GET | `/attachments/{id}/download?signature…` | No bearer token. Signed links last 10 minutes and are issued only to participants. |

### How the conversation list stays within 3 queries

1. `msg_conversations` ⨝ the caller's `msg_participants` row ⨝ last message ⨝ its sender ⨝ its
   reply-to message ⨝ that message's sender. Correlated sub-selects add `participant_count` and
   `unread_count` (served by the `msg_messages(conversation_id, id)` index).
2. Participants ⨝ users for every conversation on the page. `ROW_NUMBER()` caps groups at 5
   participants in the list. The detail view returns all of them.
3. Attachments of the last messages. This query runs only when some last message is a file.

`tests/Feature/QueryCountTest.php` checks this with `DB::getQueryLog()` on inboxes of 5 and 30
conversations that mix replies, files, entity cards and deleted messages.

---

## 6. Realtime (Reverb / Pusher protocol)

| Channel | Events (`broadcastAs`, bind **without** a leading dot) |
|---|---|
| `private-messaging.user.{userId}` (owner only) | `message.created`, `conversation.added`, `conversation.removed`, `conversation.read`, plus the call events |
| `private-messaging.conversation.{id}` (active participants) | `message.sent`, `message.edited`, `message.deleted`, `read.updated`, `delivered.updated` `{conversation_id, user_id, last_delivered_message_id}` (not sent when a read raises delivered: `read.updated` alone covers it, clients treat read ≥ delivered). Typing uses the client event `client-typing` (whisper, no server involvement). Reverb must accept client events: `accept_client_events_from` in `config/reverb.php` (env `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM`) defaults to `members`, which allows them for authorized subscribers. |
| `presence-messaging.presence` (any authenticated user) | member info `{id, name}` |

Every event implements `ShouldBroadcastNow` and `ShouldDispatchAfterCommit`. Events are also
dispatched through `DB::afterCommit`, so they go out only after the data is committed and never
through the default queue. If Reverb is down, the error is reported but the API request still
succeeds. Clients catch up with `?after=` when they reconnect.

### Reverb deployment notes

- Reverb is a **separate long-running process**: `php artisan reverb:start --host=0.0.0.0 --port=8080`.
  Run it under supervisor or systemd and restart it on deploy (`php artisan reverb:restart`).
- Put it behind the web server as a **WebSocket reverse proxy**, for example
  `wss://chat.example.com/app/{key}` → `127.0.0.1:8080`. OpenLiteSpeed/CyberPanel needs a
  WebSocket proxy context (an "External App" of type web server pointing at `127.0.0.1:8080`,
  plus a `/app` and `/apps` WebSocket proxy context). Configs, firewall and sizing are in
  [`deploy/README.md`](../../../../deploy/README.md).
- The API server must reach Reverb's HTTP API (`REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME`) to
  publish events and to read presence for push targeting.
- Behind a proxy, trust it (`TrustProxies`) so `request()->root()` is correct. Download links are
  signed on the **relative** path, so host/scheme rewriting does not break them.
- With ~50 users a single Reverb process is plenty. To scale horizontally, enable Reverb's
  Redis scaling.

---

## 7. Push (FCM HTTP v1)

`SendPushJob` is queued after commit for every new message. It sends a high-priority **data-only**
message to participants other than the sender who are **not muted** and **not online**, according
to `PresenceChecker`.

Payload (all values are strings):
`{kind: "message", conversation_id, message_id, sender_name, preview, type, conversation_type, conversation_title}`.

The OAuth2 token comes from a service-account JWT signed with `openssl_sign` (RS256) and is
cached until shortly before it expires. No Google SDK is used. Tokens that FCM reports as
`UNREGISTERED`/`NOT_FOUND` are deleted. Set `MESSAGING_PUSH_DRIVER=fcm` and
`MESSAGING_FCM_CREDENTIALS=/secure/path/service-account.json`.

### Queue worker

Push (`SendPushJob`) and the call jobs (`RingTimeoutJob`, `SendCallPushJob`) are queued.
Any queue driver works (the harness uses `QUEUE_CONNECTION=database`). Run a worker under
supervisor or systemd and restart it on deploy:

```bash
php artisan queue:work --queue=default --tries=3
php artisan queue:restart   # after each deploy
```

With `QUEUE_CONNECTION=sync`, pushes are sent inline and ring-timeout delays are ignored
(see Calls › Queue).

---

## 8. Testing

Pest tests live in `tests/` and run from a host or harness app:

```bash
cd backend
php artisan test                 # harness, Laravel 13
```

The harness `tests/Pest.php` binds `Tests\TestCase` with `RefreshDatabase` to
`../packages/hypermed/messaging/tests` and loads `tests/helpers.php`. The tests cover:
direct-conversation uniqueness, idempotent `client_uuid`, 403 for non-participants on every
endpoint and on channel auth, unread counts, a read pointer that never moves backwards,
gap- and duplicate-free `before`/`after` pagination, after-commit broadcasting (including
rollback), ≤ 3 list queries, deleted placeholders, attachments with signed URLs, push targeting,
FCM JWT and token pruning, and the rate limit.

---

## 9. Manual test checklist

Run these with the Flutter example app against the harness (API + `reverb:start` + `queue:work`):

- [ ] **Wi‑Fi ↔ mobile data switch mid-chat**: while chatting on Android, toggle Wi‑Fi off and back on.
      The socket reconnects with backoff. Messages sent meanwhile go to the outbox and are delivered
      once, and messages received meanwhile appear through `after=` catch-up with no gaps or duplicates.
- [ ] **Phone locked 10 minutes, then reopened**: messages sent while locked arrive as FCM
      data pushes (the user is not in presence). Tapping the notification opens the right chat.
      On unlock the list and unread badges are correct and the chat is caught up.
- [ ] **Desktop minimized to tray**: incoming messages raise a local notification and update the
      tray badge. Restoring the window shows them already loaded. Mark-read only happens when the
      chat is actually visible.
- [ ] **Two users sending simultaneously**: both type and send at the same moment in a direct and
      in a group. Both clients show both messages exactly once and in the same (id) order. Typing
      indicators appear and clear, and each sender's bubble goes ⏱ → ✓.
- [ ] Revoked or expired attachment link: open an image URL after 10+ minutes. The server answers 403
      and the client refetches the message for a fresh link.
- [ ] Non-participant: removing a user from a group stops their realtime events
      (`conversation.removed`), and the API returns 403.

---

## 10. Calls

Voice and video calls (1:1 and group) using a self-hosted **LiveKit** SFU. Laravel only
issues LiveKit access tokens and keeps track of call state. It never handles media.
Signaling goes over Reverb on the existing `private-messaging.user.{id}` channel. A
high-priority FCM data push reaches users who have no socket connected.

Everything lives in `src/Calls/**` and is registered by
`Hypermed\Messaging\Calls\CallsServiceProvider` (auto-discovered through
`extra.laravel.providers`). That provider loads `config/messaging-calls.php`, the
`msg_calls` / `msg_call_participants` migration and `routes/calls.php`. The routes use the
same `messaging.route_prefix` and `messaging.middleware` as the rest of the package.

### .env keys

| Key | Default | Meaning |
|---|---|---|
| `LIVEKIT_URL` | (empty) | WebSocket URL that clients connect to, e.g. `wss://livekit.example.com` |
| `LIVEKIT_API_KEY` | (empty) | LiveKit API key (JWT `iss`) |
| `LIVEKIT_API_SECRET` | (empty) | LiveKit API secret. Signs access tokens (HS256) and verifies webhooks |
| `LIVEKIT_TOKEN_TTL` | `300` | Access-token lifetime in seconds. It only has to cover the join, because LiveKit refreshes tokens for connected clients |
| `LIVEKIT_WEBHOOK_LEEWAY` | `60` | Allowed clock skew (s) when checking webhook JWT `exp`/`nbf` |
| `MESSAGING_CALL_RING_TIMEOUT` | `45` | Seconds before unanswered invitees are marked `missed` |
| `MESSAGING_CALLS_ENABLED` | `true` | Set to `false` to leave the calls routes unregistered |
| `MESSAGING_CALL_QUEUE_CONNECTION` / `MESSAGING_CALL_QUEUE` | null | Optional queue connection and queue for the ring-timeout and call-push jobs |

Publish the config with `php artisan vendor:publish --tag=messaging-calls-config` (optional).

### Gate

`messaging.call` decides who may start or join a call. If the host app defines no gate,
the package registers a default one that returns `true`. Separately, only **active
participants** of a conversation can start a call in it, accept or join its calls, read
them, or receive a token.

### Lifecycle

```
ringing ──accept──▶ ongoing ──last participant leaves / room_finished──▶ ended
   ├── caller cancels before any answer ─────────────────▶ cancelled
   ├── every invitee declines ───────────────────────────▶ declined
   └── ring timeout (45 s) or room closed while ringing ─▶ missed
```

- **One active call per conversation.** `POST /conversations/{id}/calls` returns the
  existing ringing or ongoing call with status 200 and a token, instead of creating a
  new one. What that means for the person calling:
  - If they are the caller, they get a fresh token.
  - If they were still being rung (both sides pressed "call" at once), the call is
    answered.
  - Otherwise they join the call late.
- **Ring timeout.** `RingTimeoutJob` is dispatched with a delay. If a job is lost
  because the worker was down, an expired ringing call is closed the next time someone
  starts or accepts a call in that conversation.
- **Leaving.**
  - In a **direct** conversation, the call ends as soon as either side leaves.
  - In a **group**, the call ends when the last joined participant leaves.
  - Invitees who never answered become `missed`, and they can still join an ongoing
    group call later.
- **Closing system message.** When a call reaches any terminal status, a `system`
  message is posted through `MessagingService::sendSystemMessage`. The body depends on
  the status:

  | Status | Body |
  |---|---|
  | `ended` | `Video call · 12:34` / `Voice call · 0:45` |
  | `missed`, `cancelled` | `Missed video call` / `Missed voice call` |
  | `declined` | `Declined video call` / `Declined voice call` |

  The message meta is `{call_id, call_type, duration_seconds, status}`. Clients can use
  `meta.status` to word the message differently for the caller and the callee.

### Endpoints and errors

The endpoints are listed in `docs/CONTRACT.md` (Calls). Errors besides the standard
codes:

- `invalid_call_state` (409), for example accepting a call that has already ended.
- `calls_unavailable` (503), when LiveKit env keys are missing.
- `invalid_signature` (401), from the webhook only.

### Tokens

Tokens are HS256 JWTs signed by hand (no SDK) with these claims:

- `iss` = API key
- `sub` = user id (the identity)
- `name` = display name
- `nbf`, `exp` = now and now + TTL
- `video` = `{room: "call_{id}", roomJoin, canPublish, canSubscribe, canPublishData}`

No admin, create, list or record grants are included.

### Webhook

`POST /api/messaging/livekit/webhook` has no Sanctum. Instead it checks that:

- the `Authorization` header holds a JWT signed with the API secret,
- `iss` equals the API key,
- `exp`/`nbf` are valid,
- the `sha256` claim equals base64(sha256(raw body)).

Requests that fail these checks get a 401 `invalid_signature`. The handled events
repair call state after a client crash:

| Event | Effect |
|---|---|
| `room_finished` | An ongoing call becomes `ended`. A call that was still ringing becomes `missed`. |
| `participant_left` | That participant is marked `left`. The call ends only when nobody is still joined. Stale events, and events that arrive while the call is ringing, are ignored. |
| `participant_joined` | Repairs a participant who was wrongly marked as left. |

In `livekit.yaml`, configure:

```yaml
webhook:
  api_key: <LIVEKIT_API_KEY>
  urls: [https://your-api.example.com/api/messaging/livekit/webhook]
```

### Realtime events (`private-messaging.user.{id}`)

All of these are `ShouldBroadcastNow` + `ShouldDispatchAfterCommit`.

| Event | Payload | Recipients |
|---|---|---|
| `call.incoming` | `{call}` | invitees |
| `call.accepted` | `{call, user_id}` | all call participants |
| `call.declined` | `{call, user_id}` | all call participants |
| `call.cancelled` | `{call}` | all call participants |
| `call.ended` | `{call}` | all call participants, for every terminal status except `cancelled` (`call.status` is `ended`, `missed`, `declined` or `failed`) |
| `call.participant_changed` | `{call, user_id, status}` | all call participants. Fires on join, leave and missed. On call start, the caller's own devices also receive it |
| `call.answered_elsewhere` | `{call_id}` | the answering user's own channel. The socket in `X-Socket-ID` is excluded |

### Push (FCM data, queued, all values strings)

Every push carries `{kind, call_id, conversation_id, call_type, caller_id, caller_name}`.

| `kind` | Sent to |
|---|---|
| `call.incoming` | invitees who are offline according to `PresenceChecker` |
| `call.cancelled` / `call.ended` | invitees who were still ringing when the call was cancelled, timed out or finished. This dismisses the full-screen ringing UI |
| `call.answered_elsewhere` | the user who just accepted or declined. It dismisses ringing on their other devices. A client that is itself in that call must ignore it |

Mute settings do not suppress call pushes.

### Queue

`RingTimeoutJob` and `SendCallPushJob` need a running queue worker
(`php artisan queue:work`). The `sync` driver ignores delays. The timeout job detects
that it ran early and does nothing; the lazy expiry described under Lifecycle covers it.

### Manual test checklist (calls)

- 1:1 video over mobile data on both ends.
- Caller on Wi-Fi and callee on 4G behind CGNAT. The call must connect via TURN/TLS 443.
- Group call with 5 or more participants. Join late; let the last person leave and check the call ends.
- Switch network (Wi-Fi to data) mid-call. The call must not drop and the UI shows "Reconnecting…".
- Answer on desktop while the phone is also ringing. The phone stops ringing (`call.answered_elsewhere`).
- A locked Android phone rings through the full-screen notification triggered by FCM.
- Tecno/Infinix device with battery optimization on vs off.
- Kill the app mid-call. LiveKit `participant_left` / `room_finished` webhooks close the call.
- Nobody answers. After 45 s the call shows "Missed voice call" in the chat.
