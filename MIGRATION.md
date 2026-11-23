# Migrating

## 4.x → 5.x

The old SSOfy environment variables are no longer read. If you still set any, rename them:
`SSOFY_URL` → `NINE_ISSUER`, `SSOFY_CLIENT_ID` / `SSOFY_CLIENT_SECRET` → `NINE_CLIENT_ID` /
`NINE_CLIENT_SECRET`, `SSOFY_WEBHOOK_SECRET` → `NINE_WEBHOOK_SECRET`, `SSOFY_CACHE_DRIVER` →
`NINE_CACHE_DRIVER`. 5.0 requires `9in/php-sdk` ^5.0, which dropped them too. Nothing else changes.

## 3.x → 4.x

4.0 moves to `9in/php-sdk` 4 and `9in/laravel-events` 3, which read 9in's snake_case payloads
(the 9in release of 2026-11-10). Upgrade together with 9in.

```bash
composer require 9in/laravel:^4.0 -W
```

- Webhook events: `$event->type()`, `createdAt()` and `userId()` keep working; they now read
  `event_type`, `created_at` and `data.user.id`. Code that reads `$event->event['type']`,
  `['createdAt']` or `['data']['userId']` directly must use `['event_type']`, `['created_at']` and
  `['data']['user']['id']`. `token.revoked` data is `token_type` and `client_id`.
- `Nine::management()`: send and read the API's snake_case names (`email_verified`,
  `phone_number`, `phone_number_verified`, `password_hash`; `created_at`, `last_login_at` as
  ISO 8601 strings); the old names are refused. `findUser('phone_number', …)` replaces
  `findUser('phone', …)`. The full table is in
  [`9in/php-sdk`'s MIGRATION.md](https://github.com/9in-io/php-sdk/blob/master/MIGRATION.md).
- Hybrid token verification on `/nine/webhook` reads the new events; nothing to change.
