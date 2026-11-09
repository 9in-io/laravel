# CHANGELOG

## 3.0.0 - unreleased

Rebranded as **9in** (`9in/laravel`, namespace `Nine\Laravel`) and moved to standard OpenID Connect. See [MIGRATION.md](MIGRATION.md).

* `/nine/login`, `/nine/callback`, `/nine/logout` with PKCE S256, ID token verification, session regeneration, refresh token rotation and RP-initiated logout.
* `nine` guard: bearer access tokens with every key-library algorithm (RS/PS/ES/EdDSA from JWKS, HS with `nine.api.secret`), opaque tokens, and hybrid verification by default with client credentials (`HybridTokenVerifier` + Laravel cache; `nine.api.mode`); browser sessions from the ID token; optional mapping to your own user provider.
* `/nine/webhook` evicts cached token answers on `token.revoked`, `session.revoked` and `user.*` events.
* Middleware `nine` (`:redirect`, `:passive`), `nine.scope`, `nine.webhook`.
* Webhook endpoint dispatching `Nine\Laravel\Events\*` (9in/laravel-events 2).
* `Nine` facade with `client()`, `verifier()`, `management()`.
* Webhooks are dispatched once per event id (`nine.webhook.dedupe`, 409 while a delivery is still running).
* The issuer must use https (`nine.allow_insecure_issuer` / `NINE_ALLOW_INSECURE_ISSUER` for development hosts).
* `NINE_*` environment variables with `SSOFY_*` fallbacks.
* Removed: SSOfy API client usage, token cache and `TokenDeleted` listener, social login route, unused migrations.
* PHP 8.1+, Laravel 10–12. Feature tests with Orchestra Testbench.

## 2.0.3 - 2023-08-05

* php-sdk changes adaption.

## 2.0.2 - 2023-07-30

* php-sdk changes adaption.

## 2.0.1 - 2023-07-01

* Hotfix: fixed the token cache invalidation issue.

## 2.0.0 - 2023-06-18

* Upgraded to `ssofy/php-sk:2.0.0`.
* Removed SSO Server functionality.
* New Facade methods for various operations.
* New config file structure.
* Listener for `TokenDeleted` event.
* Added the route for logout (`/sso/logout?everywhere=0&redirect_uri=...`).
* Added the route for social login (`/sso/social/google`).
* Bug Fixes.

## 1.0.4 - 2023-06-02

* Fixed the issue with event payload validation.
* Fixed the issue with `user_add` and `user_update`.
* Fixed the issue with empty `name` attribute.

## 1.0.3 - 2023-06-02

* Improved user search functionality using login, email, and phone.

## 1.0.2 - 2023-06-01

* Fixed the issue with `email_verified` and `phone_verified` claims.

## 1.0.1 - 2023-06-01

* Handled `user_updated` (profile updates) and `user_added` (registration).
* Added config options for authentication methods.
* Fixed the issue with passwordless authentication.

## 1.0.0 - 2023-03-24

* First Release.
