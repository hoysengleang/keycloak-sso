<?php

namespace PhAb\KeycloakSso\Resolvers;

use Illuminate\Contracts\Config\Repository;
use PhAb\KeycloakSso\Contracts\ConfigResolver;
use PhAb\KeycloakSso\Data\ConnectionConfig;

/**
 * Default resolver: reads credentials from the package config file
 * (config/keycloak-sso.php -> connections), which is env-driven.
 */
class ConfigConnectionResolver implements ConfigResolver
{
    public function __construct(protected Repository $config) {}

    public function resolve(string $environment): ConnectionConfig
    {
        $connections = (array) $this->config->get('keycloak-sso.connections', []);

        $connection = $connections[$environment] ?? $connections['default'] ?? [];

        return ConnectionConfig::fromArray($connection);
    }
}
