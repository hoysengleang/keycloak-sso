<?php

namespace PhAb\KeycloakSso\Data;

/**
 * The outcome of a successful login, from either Keycloak or the DB fallback.
 */
final class LoginResult
{
    public const SOURCE_KEYCLOAK = 'keycloak';

    public const SOURCE_FALLBACK = 'fallback';

    /**
     * @param  string  $source  One of the SOURCE_* constants.
     * @param  array<string, mixed>|null  $token  Raw Keycloak token payload (null for fallback logins).
     * @param  mixed  $user  Keycloak userinfo array, or whatever the fallback callback returned.
     */
    public function __construct(
        public readonly string $source,
        public readonly mixed $user,
        public readonly ?array $token = null,
    ) {}

    public function fromKeycloak(): bool
    {
        return $this->source === self::SOURCE_KEYCLOAK;
    }

    public function fromFallback(): bool
    {
        return $this->source === self::SOURCE_FALLBACK;
    }

    public function accessToken(): ?string
    {
        return $this->token['access_token'] ?? null;
    }

    public function refreshToken(): ?string
    {
        return $this->token['refresh_token'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'token'  => $this->token,
            'user'   => $this->user,
        ];
    }
}
