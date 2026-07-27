<?php

namespace PhAb\KeycloakSso\Contracts;

use PhAb\KeycloakSso\Data\ConnectionConfig;

interface ConfigResolver
{
    /**
     * Resolve the Keycloak connection credentials for the given environment.
     *
     * @param  string  $environment  The application environment (e.g. "production").
     */
    public function resolve(string $environment): ConnectionConfig;
}
