<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application
    |--------------------------------------------------------------------------
    |
    | Your 9in tenant (or custom login domain) and the application registered for
    | this site in the console. Old SSOFY_* variables are still read as fallbacks.
    |
    */

    'issuer'        => env('NINE_ISSUER', env('SSOFY_URL')),
    'client_id'     => env('NINE_CLIENT_ID', env('SSOFY_CLIENT_ID')),
    'client_secret' => env('NINE_CLIENT_SECRET', env('SSOFY_CLIENT_SECRET')),

    // Absolute, or relative to APP_URL. Register it as a redirect URL of the application.
    'redirect_uri' => env('NINE_REDIRECT_URI', '/nine/callback'),

    // Where /nine/logout sends users after 9in; register it as a logout URL.
    'post_logout_redirect_uri' => env('NINE_LOGOUT_REDIRECT_URI', '/'),

    'scopes' => ['openid', 'profile', 'email', 'offline_access'],

    // API identifier to request access tokens for (aud claim), if you call your own API.
    'audience' => env('NINE_AUDIENCE'),

    // Language of the hosted pages (ui_locales); null follows the browser.
    'locale' => null,

    // The issuer must use https (plain http is accepted for localhost and *.localhost).
    // Set to true only for development setups such as http://auth:8897 in Docker.
    'allow_insecure_issuer' => (bool) env('NINE_ALLOW_INSECURE_ISSUER', false),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | GET  /{prefix}/login?return_to=/path   starts a sign-in (route name nine.login)
    | GET  /{prefix}/callback                completes it (nine.callback)
    | GET  /{prefix}/logout                  signs out here and at 9in (nine.logout)
    | POST /{prefix}/webhook                 receives webhooks when a secret is set (nine.webhook)
    |
    */

    'routes' => [
        'enabled'    => true,
        'prefix'     => env('NINE_ROUTE_PREFIX', 'nine'),
        'middleware' => ['web'],
        'home'       => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | API tokens
    |--------------------------------------------------------------------------
    |
    | Requests with an `Authorization: Bearer` header are authenticated with the
    | access token (JWT signed with any of the tenant's keys, or opaque `9in_at_…`).
    | Tokens must be issued for this audience (when set).
    |
    | mode:
    |   hybrid      verify locally, ask 9in once per token, cache the answer (in
    |               `cache.store`) until a revocation webhook arrives or `cache_ttl`
    |               passes. Needs client_id + client_secret (a confidential app).
    |   local       signature and claims only; a revoked token works until it expires.
    |   introspect  ask 9in on every request.
    | Default: hybrid when client_id and client_secret are set, otherwise local.
    |
    | secret: the shared secret of an HMAC key (HS256/384/512), when your API's
    | tokens are signed with one in the console's key library.
    |
    */

    'api' => [
        'audience'  => env('NINE_API_AUDIENCE', env('NINE_AUDIENCE')),
        'mode'      => env('NINE_TOKEN_VERIFICATION'),
        'secret'    => env('NINE_API_SECRET'),
        'cache_ttl' => (int) env('NINE_TOKEN_CACHE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Signing secret of the webhook endpoint you created in the console (Advanced →
    | Webhooks) for https://your-app.com/{prefix}/webhook. Verified events are
    | dispatched as Nine\Laravel\Events\* (9in/laravel-events).
    |
    */

    'webhook' => [
        'secret'          => env('NINE_WEBHOOK_SECRET', env('SSOFY_WEBHOOK_SECRET')),
        'previous_secret' => env('NINE_WEBHOOK_SECRET_PREVIOUS'),
        'tolerance'       => 300,

        // Dispatch each event once: retries and redeliveries of a processed event id
        // are acknowledged without dispatching again (ids kept in `cache.store`).
        'dedupe'     => true,
        'dedupe_ttl' => 259200, // seconds (3 days; 9in retries for about a day)
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Store for the discovery document, signing keys and processed webhook ids
    | (null = default store). Use a shared store when you run several servers.
    |
    */

    'cache' => [
        'store' => env('NINE_CACHE_DRIVER', env('SSOFY_CACHE_DRIVER')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Management API
    |--------------------------------------------------------------------------
    |
    | Credentials of a confidential application with management scopes. Defaults
    | to the application above.
    |
    */

    'management' => [
        'client_id'     => env('NINE_MANAGEMENT_CLIENT_ID'),
        'client_secret' => env('NINE_MANAGEMENT_CLIENT_SECRET'),
    ],
];
