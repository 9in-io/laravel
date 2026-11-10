9in for Laravel
===============

Sign users in to your Laravel app with [9in](https://9in.io), protect your API with 9in
access tokens, and receive verified webhooks.

<img src="docs/banner.png" width="160" alt="9in"/>

- **Browser sign-in**: OpenID Connect authorization code flow with PKCE (`/nine/login`,
  `/nine/callback`, `/nine/logout`). The ID token is verified, tokens are kept in the
  Laravel session and refreshed (with rotation) when they expire.
- **API tokens**: `Authorization: Bearer …` is verified locally against your tenant's
  signing keys (cached in Laravel's cache): no call to 9in per request.
- **`nine` guard and middleware**, scope checks, and a `Nine` facade.
- **Webhooks** at `/nine/webhook`, dispatched as Laravel events.

Requires PHP 8.1+ and Laravel 10, 11, 12 or 13 (Laravel 13 itself needs PHP 8.3+).

```bash
composer require 9in/laravel:^4.0
```

Upgrading from `ssofy/laravel` 2.x? Read [MIGRATION.md](MIGRATION.md).

## Setup

Create an application in the 9in console (Applications → New → Regular web app) with the
redirect URL `https://your-app.com/nine/callback` and the logout URL
`https://your-app.com/`, then:

```dotenv
NINE_ISSUER=https://acme.9in.io          # or your custom login domain
NINE_CLIENT_ID=app_…
NINE_CLIENT_SECRET=…
# NINE_AUDIENCE=https://api.acme.com     # access tokens for your own API
```

The old `SSOFY_URL`, `SSOFY_CLIENT_ID` and `SSOFY_CLIENT_SECRET` are still read when the
`NINE_*` names are not set. The issuer must use `https://`; plain http is accepted for
`localhost` and `*.localhost` (set `NINE_ALLOW_INSECURE_ISSUER=true` for other development
hosts). Publish the config with
`php artisan vendor:publish --tag=nine:config` to change routes, scopes or caching.

## Protect pages

```php
Route::middleware(['web', 'nine:redirect'])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();     // Nine\Laravel\User: id (= sub), email, name, … from the ID token
        return view('dashboard', compact('user'));
    });
});

// Links
route('nine.login', ['return_to' => '/dashboard']);   // add 'screen' => 'signup' for the sign-up form
route('nine.logout');
```

| Middleware | Signed out |
|---|---|
| `nine` | 401 JSON |
| `nine:redirect` | Browsers go to the 9in login and come back; API requests get 401 |
| `nine:passive` | Continue; `auth('nine')->user()` is null |
| `nine.scope:orders:read,orders:write` | 403 unless the bearer token has every scope (after `nine`) |

The `nine` guard is registered automatically (`auth('nine')`); `nine` middleware also makes
it the default guard for the request.

### Using your own users table

Add a column holding the 9in user id (`nine_id`) and point the guard at your provider in
`config/auth.php`:

```php
'guards' => [
    'nine' => ['driver' => 'nine', 'provider' => 'users', 'local_key' => 'nine_id'],
],
```

`auth()->user()` is then your Eloquent user (null until you link one, e.g. in a
`UserCreated` webhook listener or on first sign-in using `auth('nine')->claims()`).

## Protect your API

```php
Route::middleware(['nine', 'nine.scope:orders:read'])->get('/api/orders', function () {
    $sub = auth()->id();                  // 9in user id
    $claims = auth('nine')->claims();     // access-token claims: client_id, scope, aud, …
});
```

Tokens must be issued for `nine.api.audience` (`NINE_API_AUDIENCE`, defaults to
`NINE_AUDIENCE`). `Nine::verifyAccessToken($token, ['scope' => …])` does the same by hand.

- **Every key-library algorithm** is accepted: RS256/384/512, PS256/384/512, ES256, ES384,
  EdDSA, and HS256/384/512 with the API's shared secret in `NINE_API_SECRET`. Only access
  tokens (`typ: at+jwt`) are accepted, never ID tokens.
- **Revocations take effect at once.** With client credentials configured (`NINE_CLIENT_ID`
  / `NINE_CLIENT_SECRET`, or the `NINE_MANAGEMENT_*` pair), the guard runs in **hybrid**
  mode. Each token is checked locally, introspected once, and the answer is cached in
  `nine.cache.store` (`NINE_TOKEN_CACHE_TTL`, default 300 s). When `/nine/webhook` receives
  `token.revoked`, `session.revoked` or a `user.*` event, it drops the matching answers, so
  the next request is refused. Subscribe the webhook endpoint to those events.
- **Opaque access tokens** (`9in_at_…`) are introspected.
- **Choosing the mode:** `NINE_TOKEN_VERIFICATION=local` skips introspection (a revoked token
  works until it expires); `introspect` asks 9in on every request. Without client
  credentials the guard uses `local`.

## Webhooks

Create an endpoint in the console (Advanced → Webhooks) for
`https://your-app.com/nine/webhook` and set its signing secret:

```dotenv
NINE_WEBHOOK_SECRET=whsec_…
```

Verified events (signature over the raw body and path, at most five minutes old) are
dispatched as [`9in/laravel-events`](https://github.com/9in-io/laravel-events) classes:

```php
use Nine\Laravel\Events\UserCreated;

Event::listen(UserCreated::class, function (UserCreated $event) {
    $user = $event->user();       // the 9in user (Management API shape: snake_case, ISO 8601 times)
    // $event->id() is stable across retries: use it to ignore duplicates
});
```

The raw payload (`$event->event`) is `{id, event_type, created_at, tenant, environment, data}`, and
`data.user` is the user the event is about.

Each event is dispatched **once**: retries and redeliveries of a processed event id are
answered 204 without dispatching again, and a retry that arrives while the first delivery is
still running gets 409 (9in tries again later). If a listener throws, the id is released
and 9in retries. Ids are kept for 3 days in the `nine.cache.store` cache; use a shared store
(redis, database) when you run several servers. Turn it off with `nine.webhook.dedupe`.

To receive webhooks on a different route, use the `nine.webhook` middleware there and read
`$request->attributes->get('nine.webhook')`.

## Management API

```php
use Nine\Laravel\Facades\Nine;

Nine::management()->createUser(['email' => 'jane@acme.com', 'email_verified' => true]);   // the API's snake_case names
Nine::management()->revokeUserSessions($userId);
$link = Nine::management()->createLoginLink($userId, ['returnTo' => url('/nine/callback')]);
```

The application needs management scopes. Set `NINE_MANAGEMENT_CLIENT_ID` /
`NINE_MANAGEMENT_CLIENT_SECRET` to use a separate machine-to-machine application.

Keeping users in your own database instead? Add
[`9in/laravel-server`](https://github.com/9in-io/laravel-server).

## Tests

```bash
composer install
composer test
```

## License

The MIT License (MIT). See [LICENSE](LICENSE). 9in and SSOfy are by [Cubelet Ltd](https://cubelet.co.uk).
