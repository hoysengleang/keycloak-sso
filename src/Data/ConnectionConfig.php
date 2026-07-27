<?php

namespace PhAb\KeycloakSso\Data;

use PhAb\KeycloakSso\Exceptions\ConfigurationException;

/**
 * Immutable set of Keycloak connection credentials for a single environment.
 */
final class ConnectionConfig
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $realm,
        public readonly string $clientId,
        public readonly string $clientSecret,
    ) {}

    /**
     * Build from an associative array, validating required keys.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        foreach (['base_url', 'realm', 'client_id', 'client_secret'] as $key) {
            if (empty($config[$key])) {
                throw new ConfigurationException("Missing Keycloak config value: [{$key}].");
            }
        }

        return new self(
            baseUrl: rtrim((string) $config['base_url'], '/'),
            realm: (string) $config['realm'],
            clientId: (string) $config['client_id'],
            clientSecret: (string) $config['client_secret'],
        );
    }

    /**
     * The realm base URL, e.g. https://sso.example.com/realms/my-realm
     */
    public function realmUrl(): string
    {
        return $this->baseUrl.'/realms/'.$this->realm;
    }

    public function tokenUrl(): string
    {
        return $this->realmUrl().'/protocol/openid-connect/token';
    }

    public function userInfoUrl(): string
    {
        return $this->realmUrl().'/protocol/openid-connect/userinfo';
    }

    public function logoutUrl(): string
    {
        return $this->realmUrl().'/protocol/openid-connect/logout';
    }
}
