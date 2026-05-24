# Shared Profiles for LinkStack

A Laravel package that extends [LinkStack](https://github.com/LinkStackOrg/LinkStack) with three features, without modifying any core LinkStack files:

1. **API link submission** — `POST /api/links` with bearer token auth; submitted links land in a moderation queue.
2. **Telegram multi-user management** — multiple Telegram accounts can authenticate as one shared LinkStack profile.
3. **Link moderation queue** — approve or reject pending links from the studio.

---

## Requirements

- PHP 8.2+
- LinkStack (Laravel 10)
- A Telegram bot (required for Telegram auth features)

---

## Installation

```bash
composer require werdswords/linkstack-shared-profiles
php artisan migrate
```

Auto-discovery registers the service provider. No changes to `config/app.php` are needed.

---

## Features

### API Link Submission

Any external tool can submit links to a profile by POSTing to `/api/links` with a bearer token.

#### Setting an API token

Generate a token for a profile via Artisan tinker:

```bash
\App\Models\User::find($profileId)->update(['api_token' => \Illuminate\Support\Str::random(80)]);
```

#### Listing pending links

```
GET /api/links
Authorization: Bearer <api_token>
```

Returns pending links for the authenticated profile:

```json
{
    "data": [
        {
            "id": 1,
            "link": "https://example.com",
            "title": "Example",
            "button_id": 1,
            "meta": { "source": "my-app" },
            "submitted_at": "2024-01-01 00:00:00"
        }
    ]
}
```

#### Approving a link

```
POST /api/links/{id}/approve
Authorization: Bearer <api_token>
```

Sets the link's status to `published`. Returns `404` if the link is not found, not in `pending` status, or belongs to a different profile.

#### Denying a link

```
DELETE /api/links/{id}
Authorization: Bearer <api_token>
```

Permanently deletes the link. Returns `404` if the link is not found, not in `pending` status, or belongs to a different profile. LinkStack does not support soft deletes on links.

#### Submitting a link

```
POST /api/links
Authorization: Bearer <api_token>
Content-Type: application/json
```

```json
{
    "link": "https://example.com",
    "title": "Example",
    "button_id": 1,
    "meta": { "source": "my-app" }
}
```

| Field | Required | Description |
|---|---|---|
| `link` | Yes | URL (max 2048 characters) |
| `title` | Yes | Display title (max 255 characters) |
| `button_id` | Yes | ID of an existing button type |
| `meta` | No | Arbitrary key/value object stored as JSON in `type_params` |

Submitted links arrive with `status = pending` unless auto-approve is enabled. This can be set globally via `LINKSTACK_SHARED_PROFILES_AUTO_APPROVE=true`, or on a per-profile basis:

```bash
\App\Models\User::find($profileId)->update(['auto_approve' => true]);
```

The per-profile value takes precedence over the global config when set. Setting it to `false` will queue links even if the global config enables auto-approve.

**Response:** `201 Created` with `{"status": "queued"}`.

### Telegram Multi-User Management

Multiple Telegram users can log in and be redirected to a shared LinkStack profile. Two authentication flows are supported.

#### Bot token configuration

Each profile can have its own bot token stored in the database. If no per-profile token is set, the package falls back to the global `TELEGRAM_BOT_TOKEN` environment variable.

Set a per-profile token via tinker:

```bash
\App\Models\User::find($profileId)->update(['telegram_bot_token' => 'your-bot-token']);
```

Or set the global fallback in `.env`:

```
TELEGRAM_BOT_TOKEN=your-bot-token
```

#### Adding Telegram managers

A Telegram manager is a Telegram user who is permitted to log in as a given profile. Add one via tinker:

```bash
\DB::table('telegram_managers')->insert([
...     'telegram_id' => '123456789',   // Telegram user ID (string)
...     'profile_id'  => 1,             // users.id of the LinkStack profile
...     'role'        => 'moderator',   // 'owner' or 'moderator'
... ]);
```

#### Approach A — Telegram Login Widget (browser-based)

Direct users to:

```
/telegram-auth/{profileId}
```

where `{profileId}` is the `users.id` of the target LinkStack profile. The package handles the OAuth redirect and callback at `/telegram-auth/{profileId}/callback`.

Configure your Telegram bot to allow the Login Widget for your domain, then set the Socialite driver config in `config/services.php`:

```php
'telegram' => [
    'client_id'     => env('TELEGRAM_BOT_ID'),   // numeric bot ID (without the bot_ prefix)
    'client_secret' => env('TELEGRAM_BOT_TOKEN'), // bot token (used as global fallback)
    'redirect'      => '',                         // set dynamically per-profile; leave blank
],
```

#### Approach B — Telegram Mini App (initData)

A Telegram Mini App can authenticate by posting `Telegram.WebApp.initData` directly:

```
POST /telegram-login
Content-Type: application/json
```

```json
{
    "init_data": "<Telegram.WebApp.initData value>"
}
```

The controller verifies the HMAC signature and checks that the `auth_date` is within the last 5 minutes (configurable via `auth_date_ttl`). On success it returns:

```json
{"redirect": "/studio/index"}
```

The Mini App should then navigate to the returned URL.

### Link Moderation Queue

Authenticated profile users can review pending links at:

```
/studio/moderation
```

Approve or reject individual links from there. Only links belonging to the authenticated user are shown.

#### Customising the view

Publish the view to override the default layout:

```bash
php artisan vendor:publish --tag=linkstack-shared-profiles-views
```

The file will be published to `resources/views/vendor/linkstack-shared-profiles/moderation/index.blade.php`.

---

## Telegram Bot Setup

Run the following once per installation to connect the bot to your server.

### Webhook registration

Register the webhook with Telegram so updates are pushed to your application:

```bash
curl -X POST "https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/setWebhook" \
  -d "url=https://yourdomain.com/telegram/webhook" \
  -d "secret_token={TELEGRAM_WEBHOOK_SECRET}" \
  -d "allowed_updates=[\"message\",\"callback_query\"]"
```

Each incoming update will carry an `X-Telegram-Bot-Api-Secret-Token` header. The package validates it against `TELEGRAM_WEBHOOK_SECRET` and returns `403` if it does not match.

The webhook handles two update types:

- **`message`** — Responds to the `/auth` command by sending the moderator a private DM with a login button.
- **`callback_query`** — Handles `approve:{id}` and `reject:{id}` inline button taps from moderator notification messages.

### Contributor Mini App registration

Run once per group. Links submitted through the Mini App are routed to a profile by matching `initData.chat.id` against `users.telegram_group_chat_id`, so that column must be set before the bot can accept submissions.

**1. Set the group chat ID on the profile:**

```bash
php artisan tinker
\App\Models\User::find($profileId)->update(['telegram_group_chat_id' => '-1001234567890']);
```

**2. Register the Mini App as the group's menu button:**

```bash
curl -X POST "https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/setChatMenuButton" \
  -d "chat_id={GROUP_CHAT_ID}" \
  -d 'menu_button={"type":"web_app","text":"Submit a Link","web_app":{"url":"https://yourdomain.com/telegram-app/submit"}}'
```

Members can then open the form from the button in the message bar. `initData.chat.id` is automatically set to the group's ID, so submissions from different groups are routed to the correct profile without any additional configuration.

### Moderator Mini App

The moderator Mini App at `/telegram-app/moderate` does not require a menu button registration — it is accessed by sharing the URL directly with known moderators or linking to it from a pinned message. It authenticates using the existing `POST /telegram-login` endpoint, establishes a Laravel session, and redirects to `/studio/moderation`.

---

## Configuration

Publish the config file if you want to modify defaults:

```bash
php artisan vendor:publish --tag=linkstack-shared-profiles
```

| Key | Env var | Default | Description |
|---|---|---|---|
| `bot_token` | `TELEGRAM_BOT_TOKEN` | — | Global fallback bot token used for HMAC verification and sending messages when no per-profile token is set |
| `auto_approve` | `LINKSTACK_SHARED_PROFILES_AUTO_APPROVE` | `false` | Publish submitted links immediately instead of queuing them. Can be overridden per profile via `users.auto_approve`. |
| `auth_date_ttl` | — | `300` | Seconds before a Telegram Mini App `initData` payload is considered stale |
| `default_button_id` | `TELEGRAM_DEFAULT_BUTTON_ID` | — | `buttons.id` assigned to every link submitted via the Telegram contributor Mini App |
| `webhook_secret` | `TELEGRAM_WEBHOOK_SECRET` | — | Shared secret validated against the `X-Telegram-Bot-Api-Secret-Token` header on incoming webhook updates |

---

## License

MIT
