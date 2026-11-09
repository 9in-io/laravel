# Migrating from `ssofy/laravel` 2.x to `9in/laravel` 3.x

SSOfy is now [9in](https://9in.io). 3.0 is a new major version under a new package name
that uses standard OpenID Connect and verifies tokens locally.

**Upgrading is required to use 9in.** `ssofy/laravel` 2.x only talks to the SSOfy service (the signed `/v1/authenticated/*` API and SSOfy-specific login URLs). That service has shut down (ssofy.com has lapsed), and 9in does not serve the SSOfy v1 protocol, so 2.x does not work against 9in. The old versions stay published but are no longer maintained. Move to 3.x as part of moving the app to 9in.

## 1. Packages

```bash
composer remove ssofy/laravel ssofy/laravel-events ssofy/php-sdk
composer require 9in/laravel:^3.0
```

PHP 8.1+ and Laravel 10–13 are required. Namespace `SSOfy\Laravel\…` → `Nine\Laravel\…`;
provider `Nine\Laravel\NineServiceProvider` and facade `Nine` are auto-discovered.

## 2. Configuration

Publish `config/nine.php` (`php artisan vendor:publish --tag=nine:config`) and delete
`config/ssofy.php`.

| `ssofy.php` / `.env` | `nine.php` / `.env` |
|---|---|
| `oauth2.url` (`SSOFY_URL`) | `issuer` (`NINE_ISSUER`; `SSOFY_URL` still read) |
| `oauth2.client_id`, `client_secret` (`SSOFY_CLIENT_*`) | `client_id`, `client_secret` (`NINE_CLIENT_*`; old names still read) |
| `oauth2.redirect_uri` `/sso/callback` | `redirect_uri` `/nine/callback` (register it in the 9in application) |
| `oauth2.scopes` `['*']` | `scopes` `['openid', 'profile', 'email', 'offline_access']` |
| `oauth2.pkce_*` | Removed: PKCE S256 is always used |
| `oauth2.state.store`, `.ttl` | Removed: the pending login and the tokens live in the Laravel session |
| `oauth2.timeout`, `locale` | `locale` |
| `api.domain`, `api.key`, `api.secret` (`SSOFY_API_*`) | Removed: tokens are verified locally (hybrid with introspection when client credentials are set); `api.audience`, `api.mode`, `api.secret` (HMAC keys) and `api.cache_ttl` replace them |
| `cache.store`, `cache.ttl` (`SSOFY_CACHE_*`) | `cache.store` (`NINE_CACHE_DRIVER`) for signing keys; no token cache |
| | New: `webhook.secret` (`NINE_WEBHOOK_SECRET`), `routes.prefix`, `management.*` |

## 3. Routes

| 2.x | 3.x |
|---|---|
| `GET /sso/callback` | `GET /nine/callback` (`nine.callback`) |
| `GET /sso/logout` (`sso.logout`), `?everywhere=1` | `GET /nine/logout` (`nine.logout`); sign out everywhere with `Nine::management()->revokeUserSessions()` |
| `GET /sso/social/{provider}` (`sso.social`) | Removed: social buttons are on the hosted login page |
| Login started by the middleware only | Also `GET /nine/login?return_to=…` (`nine.login`), `screen=signup` for registration |
| | `POST /nine/webhook` (`nine.webhook`) |

Set `NINE_ROUTE_PREFIX=sso` to keep `/sso/*` URLs.

## 4. Guard, provider and middleware

| 2.x | 3.x |
|---|---|
| Guard driver `ssofy` + provider driver `ssofy` in `config/auth.php` | Guard `nine` is registered for you; remove the `ssofy` guard and provider |
| `ServiceGuard`: token checked with a signed SSOfy API call (cached) | `NineGuard`: bearer tokens verified locally (RS256 + JWKS), sessions from the ID token |
| `UserProvider::retrieveById()` via the SSOfy API | User built from claims (`Nine\Laravel\User`), or your own provider with `'provider' => 'users', 'local_key' => 'nine_id'` |
| `$user->id`, `email`, `name`, `picture`… (SSOfy `UserEntity`) | `$user->id` (= `sub`), `email`, `email_verified`, `name`, `picture`, `phone_number`… (OIDC claims) |
| Middleware `ssofy`, `ssofy:redirect`, `ssofy:passive` | `nine`, `nine:redirect`, `nine:passive` |
| Unauthenticated: `vendor.ssofy.error` view | 401 JSON with `WWW-Authenticate`; `nine:redirect` sends browsers to the login |
| | `nine.scope:a,b` for scope checks |

## 5. Facade and events

| 2.x | 3.x |
|---|---|
| `SSOfy` facade / `SSOfy\Laravel\Context` | `Nine` facade / `Nine\Laravel\NineManager` |
| `Context::ssoClient()` | `Nine::client()` (`Nine\OAuth2Client`) |
| `Context::defaultAPIConfig()`, `APIClient` | `Nine::verifier()`, `Nine::management()` |
| `Context::initiateAuthorization($uri)` | `Nine::redirectToLogin(session(), $uri)` |
| `Context::logout($uri, $everywhere)` | `Nine::logout(session(), $uri)` |
| `TokenDeleted` listener (token cache invalidation) | `/nine/webhook` evicts cached token answers on `token.revoked`, `session.revoked` and `user.*` (hybrid mode) |
| | Webhooks dispatch `Nine\Laravel\Events\*` |

## 6. Database

2.x shipped `add_missing_columns_to_users_table` and `create_user_social_links_table`
migrations that the client did not use. 3.x ships none. Keep or drop those tables as you
see fit.
