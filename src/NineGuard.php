<?php

namespace Nine\Laravel;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Nine\Exceptions\NineException;

/**
 * `nine` auth driver.
 *
 * - `Authorization: Bearer <access token>`: verified locally against the tenant's keys
 *   (audience from `nine.api.audience`); the user is built from the token's claims.
 * - Otherwise the browser session from /nine/callback: the user is built from the ID
 *   token; tokens are refreshed when they expire.
 *
 * With a `provider` in the guard config (e.g. Eloquent users), the guard returns your
 * local user whose `local_key` column (default `nine_id`) equals the 9in user id instead.
 */
class NineGuard implements Guard
{
    use GuardHelpers;

    /** @var Request */
    protected $request;

    /** @var NineManager */
    protected $nine;

    /** @var string */
    protected $localKey;

    /** @var array<string, mixed>|null */
    protected $claims;

    /** @var bool */
    protected $resolved = false;

    public function __construct(NineManager $nine, Request $request, ?UserProvider $provider = null, string $localKey = 'nine_id')
    {
        $this->nine     = $nine;
        $this->request  = $request;
        $this->provider = $provider;
        $this->localKey = $localKey;
    }

    public function user()
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $bearer = $this->request->bearerToken();
        if ($bearer) {
            try {
                $this->claims = $this->nine->verifyAccessToken($bearer);
            } catch (NineException $e) {
                return $this->user = null;
            }
            return $this->user = $this->resolveUser($this->claims, $bearer);
        }

        if (!$this->request->hasSession()) {
            return null;
        }
        $tokens = $this->nine->tokens($this->request->session());
        if (!$tokens) {
            return null;
        }
        $this->claims = $tokens->claims();
        return $this->user = $this->resolveUser($this->claims, $tokens->accessToken);
    }

    /**
     * Claims of the current token (access token for API calls, ID token for sessions).
     *
     * @return array<string, mixed>|null
     */
    public function claims(): ?array
    {
        $this->user();
        return $this->claims;
    }

    public function validate(array $credentials = [])
    {
        return false;
    }

    public function setUser(Authenticatable $user)
    {
        $this->user     = $user;
        $this->resolved = true;
        return $this;
    }

    public function forgetUser()
    {
        $this->user     = null;
        $this->claims   = null;
        $this->resolved = false;
        return $this;
    }

    public function setRequest(Request $request): self
    {
        $this->request = $request;
        return $this->forgetUser();
    }

    /**
     * @param array<string, mixed> $claims
     */
    protected function resolveUser(array $claims, ?string $accessToken): ?Authenticatable
    {
        if ($this->provider && !empty($claims['sub'])) {
            return $this->provider->retrieveByCredentials([$this->localKey => (string) $claims['sub']]);
        }
        return User::fromClaims($claims, $accessToken);
    }
}
