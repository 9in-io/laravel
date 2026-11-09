<?php

namespace Nine\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Nine\Exceptions\NineException;
use Nine\HybridTokenVerifier;
use Nine\Http\HttpClientInterface;
use Nine\ManagementClient;
use Nine\OAuth2Client;
use Nine\OAuth2Config;
use Nine\Storage\AtomicStorageInterface;
use Nine\TokenSet;
use Nine\TokenVerifier;

/**
 * Everything 9in in one place (also the `Nine` facade). Tokens of the signed-in user are
 * kept in the Laravel session under `nine.tokens`.
 */
class NineManager
{
    public const SESSION_TOKENS  = 'nine.tokens';
    public const SESSION_PENDING = 'nine.pending';

    /** @var Config */
    protected $config;

    /** @var HttpClientInterface */
    protected $http;

    /** @var AtomicStorageInterface */
    protected $cache;

    /** @var OAuth2Client|null */
    protected $client;

    /** @var ManagementClient|null */
    protected $management;

    /** @var HybridTokenVerifier|null */
    protected $tokenVerifier;

    public function __construct(Config $config, HttpClientInterface $http, AtomicStorageInterface $cache)
    {
        $this->config = $config;
        $this->http   = $http;
        $this->cache  = $cache;
    }

    public function client(): OAuth2Client
    {
        if ($this->client === null) {
            $config = (array) $this->config->get('nine', []);
            if (empty($config['issuer']) || empty($config['client_id'])) {
                throw new \RuntimeException('9in is not configured: set NINE_ISSUER and NINE_CLIENT_ID.');
            }
            $this->client = new OAuth2Client(new OAuth2Config([
                'issuer'        => $config['issuer'],
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'] ?? null,
                'redirect_uri'  => $this->absoluteUrl((string) ($config['redirect_uri'] ?? '/nine/callback')),
                'scopes'        => $config['scopes'] ?? OAuth2Config::DEFAULT_SCOPES,
                'audience'      => $config['audience'] ?? null,
                'locale'        => $config['locale'] ?? null,
                'http'          => $this->http,
                'cache'         => $this->cache,
                'allow_insecure_issuer' => (bool) ($config['allow_insecure_issuer'] ?? false),
            ]));
        }
        return $this->client;
    }

    /** Shared cache (also used to remember processed webhook ids). */
    public function cache(): AtomicStorageInterface
    {
        return $this->cache;
    }

    public function verifier(): TokenVerifier
    {
        return $this->client()->verifier();
    }

    public function management(): ManagementClient
    {
        if ($this->management === null) {
            $config = (array) $this->config->get('nine', []);
            $id     = $config['management']['client_id'] ?? null ?: ($config['client_id'] ?? null);
            $secret = $config['management']['client_secret'] ?? null ?: ($config['client_secret'] ?? null);
            if (empty($config['issuer']) || empty($id) || empty($secret)) {
                throw new \RuntimeException('9in Management API needs NINE_ISSUER and a confidential client (NINE_CLIENT_ID/NINE_CLIENT_SECRET).');
            }
            $this->management = new ManagementClient($config['issuer'], $id, $secret, $this->http, $this->cache, ManagementClient::SCOPES, (bool) ($config['allow_insecure_issuer'] ?? false));
        }
        return $this->management;
    }

    /**
     * Verifies a bearer access token for this app's API audience.
     *
     * @param array{audience?: string|string[], scope?: string|string[]} $options
     * @return array<string, mixed>
     */
    public function verifyAccessToken(?string $token, array $options = []): array
    {
        return $this->tokenVerifier()->verify($token, isset($options['scope']) ? ['scope' => $options['scope']] : []);
    }

    /**
     * The verifier for bearer tokens on your API routes (`nine.api.*`): hybrid by default
     * when client credentials are configured, so revocations take effect at once.
     */
    public function tokenVerifier(): HybridTokenVerifier
    {
        if ($this->tokenVerifier === null) {
            $config = (array) $this->config->get('nine', []);
            if (empty($config['issuer'])) {
                throw new \RuntimeException('9in is not configured: set NINE_ISSUER.');
            }
            $api          = (array) ($config['api'] ?? []);
            $clientId     = $config['management']['client_id'] ?? null ?: ($config['client_id'] ?? null);
            $clientSecret = $config['management']['client_secret'] ?? null ?: ($config['client_secret'] ?? null);
            $mode         = $api['mode'] ?? null ?: ($clientId && $clientSecret ? HybridTokenVerifier::MODE_HYBRID : HybridTokenVerifier::MODE_LOCAL);

            $this->tokenVerifier = new HybridTokenVerifier(array_filter([
                'issuer'              => $config['issuer'],
                'audience'            => $api['audience'] ?? null,
                'secret'              => $api['secret'] ?? null,
                'mode'                => $mode,
                'clientId'            => $clientId,
                'clientSecret'        => $clientSecret,
                'cacheTtl'            => isset($api['cache_ttl']) ? (int) $api['cache_ttl'] : null,
                'allowInsecureIssuer' => !empty($config['allow_insecure_issuer']) ?: null,
                'http'                => $this->http,
                'cache'               => $this->cache,
            ], function ($value) {
                return $value !== null && $value !== '';
            }));
        }
        return $this->tokenVerifier;
    }

    /**
     * Drops cached token answers a webhook makes stale (token.revoked, session.revoked,
     * user.*). Called by /nine/webhook; returns how many were dropped.
     *
     * @param array<string, mixed> $event
     */
    public function handleTokenEvent(array $event): int
    {
        if ($this->tokenVerifier === null && $this->config->get('nine.issuer') === null) {
            return 0;
        }
        try {
            $verifier = $this->tokenVerifier();
            return $verifier->mode() === HybridTokenVerifier::MODE_HYBRID ? $verifier->handleEvent($event) : 0;
        } catch (\Throwable $e) {
            // A misconfigured verifier must not make 9in retry the webhook forever.
            report($e);
            return 0;
        }
    }

    /**
     * Redirects to the hosted login page.
     *
     * @param array{prompt?: string, login_hint?: string, screen?: string, params?: array<string, string>} $options
     */
    public function redirectToLogin(Session $session, ?string $returnTo = null, array $options = []): RedirectResponse
    {
        $login = $this->client()->createAuthorizationUrl(array_merge($options, ['return_to' => $this->safeReturnTo($returnTo)]));

        // Several tabs may start a login at once: keep the latest few, keyed by state.
        $pending                  = (array) $session->get(self::SESSION_PENDING, []);
        $pending[$login['state']] = $login['pending'];
        $session->put(self::SESSION_PENDING, array_slice($pending, -5, null, true));

        return new RedirectResponse($login['url']);
    }

    /**
     * Completes the login: verifies the callback, stores the tokens in the session and
     * regenerates the session id.
     *
     * @throws NineException
     */
    public function handleCallback(Request $request): TokenSet
    {
        $session = $request->session();
        $state   = (string) $request->query('state', '');
        $pending = (array) $session->get(self::SESSION_PENDING, []);
        $mine    = $pending[$state] ?? null;
        unset($pending[$state]);
        $session->put(self::SESSION_PENDING, $pending);

        $tokens = $this->client()->handleCallback($request->query(), is_array($mine) ? $mine : []);

        $session->regenerate();
        $session->put(self::SESSION_TOKENS, $tokens->toArray());

        return $tokens;
    }

    /**
     * Tokens of the signed-in user, refreshed when the access token is about to expire.
     * Returns null (and forgets them) when the refresh token no longer works.
     */
    public function tokens(Session $session): ?TokenSet
    {
        $stored = $session->get(self::SESSION_TOKENS);
        if (!is_array($stored)) {
            return null;
        }
        $tokens = TokenSet::fromArray($stored);
        if (!$tokens->isExpired()) {
            return $tokens;
        }
        if (!$tokens->refreshToken) {
            $session->forget(self::SESSION_TOKENS);
            return null;
        }
        try {
            $tokens = $this->client()->refresh($tokens);
        } catch (NineException $e) {
            if ($e->getError() === 'invalid_grant' || $e->getStatus() === 400 || $e->getStatus() === 401) {
                $session->forget(self::SESSION_TOKENS);
                return null;
            }
            throw $e;
        }
        $session->put(self::SESSION_TOKENS, $tokens->toArray());
        return $tokens;
    }

    /**
     * Signs the user out of this app, revokes the refresh token and returns the redirect
     * that ends the 9in session.
     */
    public function logout(Session $session, ?string $returnTo = null): RedirectResponse
    {
        $stored = $session->get(self::SESSION_TOKENS);
        $tokens = is_array($stored) ? TokenSet::fromArray($stored) : null;
        $session->forget(self::SESSION_TOKENS);
        $session->invalidate();
        $session->regenerateToken();

        if ($tokens && $tokens->refreshToken) {
            try {
                $this->client()->revoke($tokens->refreshToken);
            } catch (\Throwable $e) {
                // best effort: the 9in logout below ends the session anyway
            }
        }

        $returnTo = $returnTo ?? (string) $this->config->get('nine.post_logout_redirect_uri', '/');

        return new RedirectResponse($this->client()->logoutUrl($tokens ? $tokens->idToken : null, $this->absoluteUrl($returnTo)));
    }

    /**
     * Only same-site paths are accepted as return_to (no open redirects).
     */
    public function safeReturnTo(?string $returnTo): string
    {
        $home = (string) $this->config->get('nine.routes.home', '/');
        if ($returnTo === null || $returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//') || str_contains($returnTo, '\\')) {
            return $home;
        }
        return $returnTo;
    }

    protected function absoluteUrl(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : url($url);
    }
}
