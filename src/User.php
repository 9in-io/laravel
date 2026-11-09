<?php

namespace Nine\Laravel;

use Illuminate\Auth\GenericUser;

/**
 * The signed-in user, built from token claims (`sub`, `email`, `name`, …). For browser
 * sessions the claims come from the ID token; for API requests from the access token.
 */
class User extends GenericUser
{
    /**
     * @param array<string, mixed> $claims
     */
    public static function fromClaims(array $claims, ?string $accessToken = null): self
    {
        return new static(array_merge($claims, [
            'id'           => (string) ($claims['sub'] ?? ''),
            'access_token' => $accessToken,
        ]));
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        $claims = $this->attributes;
        unset($claims['id'], $claims['access_token']);
        return $claims;
    }

    public function token(): ?string
    {
        return $this->attributes['access_token'] ?? null;
    }

    /** Scopes granted to the access token (API requests). */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, preg_split('/\s+/', (string) ($this->attributes['scope'] ?? '')), true);
    }
}
