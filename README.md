# Mail Reply — Flarum v2

Reply-by-email for Flarum 2, inspired by GitHub and Discourse.

## What it does

1. **Unique Reply-To per notification.** When Flarum sends an email notification
   (new post, mention, etc.) this extension injects a header like:

   ```
   Reply-To: reply+ab12cd34ef@reply.example.com
   ```

   The token encodes the recipient user + the discussion and is HMAC-signed
   against a server secret, so it cannot be forged.

2. **Inbound email processing.** When the user replies to that email, your MTA
   (or an inbound provider like Mailgun/Postmark) forwards the message to:

   - `POST /api/mail-reply/inbound` (HTTP webhook, with a shared secret), or
   - `php flarum mail-reply:process` (STDIN pipe, for Postfix/Exim aliases).

   The extension verifies the token, strips quoted history and signatures, and
   creates a `CommentPost` on behalf of the original recipient.

## Configure

Set these via the admin settings UI (Flarum settings repo keys):

| Key                                  | Description                                             |
|--------------------------------------|---------------------------------------------------------|
| `ramon-mail-reply.domain`            | Domain for the synthetic Reply-To (e.g. `reply.example.com`). Leave blank to disable. |
| `ramon-mail-reply.webhook_secret`    | Shared secret required in the `X-Mail-Reply-Token` header on inbound webhook calls. |
| `ramon-mail-reply.secret`            | (Optional) HMAC key for tokens. Falls back to `APP_KEY`. |
| `ramon-mail-reply.token_ttl_days`    | Token lifetime in days (default: 30).                   |
| `ramon-mail-reply.strip_signatures`  | `true` to run the full reply parser (default), `false` to only trim quoted lines. |

## Mailgun / Postmark / SendGrid route

Point the inbound route at:

```
POST https://yourforum.tld/api/mail-reply/inbound
Header: X-Mail-Reply-Token: <same value as ramon-mail-reply.webhook_secret>
```

## Postfix alias (STDIN pipe)

```
reply:   "|/usr/bin/php /var/www/flarum/flarum mail-reply:process"
```

## Install

```bash
composer require ramon/mail-reply
php flarum migrate
php flarum extension:enable ramon-mail-reply
```

## Admin panel

The extension ships with a small admin settings panel (domain, webhook secret,
HMAC key, token TTL, signature stripping, "via email" badge toggle).

## Forum badge

Posts created from an inbound email reply are flagged (`posts.via_mail_reply`)
and rendered with a small "via email" badge next to the post author. The badge
can be turned off from the admin settings page (`Show "via email" badge on
replies`).

## Build

```bash
cd js
npm install
npm run build
```

Outputs `js/dist/admin.js` and `js/dist/forum.js`; both are loaded automatically
by `extend.php`.
