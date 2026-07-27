<?php

namespace PhAb\KeycloakSso\Facades;

use Illuminate\Support\Facades\Facade;
use PhAb\KeycloakSso\Keycloak;

/**
 * @method static \PhAb\KeycloakSso\Data\LoginResult login(string $username, string $password, ?string $reference = null)
 * @method static array refresh(string $refreshToken)
 * @method static bool logout(string $refreshToken)
 * @method static array userInfo(string $accessToken)
 * @method static \PhAb\KeycloakSso\Data\ConnectionConfig config()
 * @method static \PhAb\KeycloakSso\Keycloak fallbackUsing(?callable $fallback)
 *
 * @see \PhAb\KeycloakSso\Keycloak
 */
class KeycloakSso extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Keycloak::class;
    }
}
