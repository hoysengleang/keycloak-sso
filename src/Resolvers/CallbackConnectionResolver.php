<?php

namespace PhAb\KeycloakSso\Resolvers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use PhAb\KeycloakSso\Contracts\ConfigResolver;
use PhAb\KeycloakSso\Data\ConnectionConfig;
use PhAb\KeycloakSso\Exceptions\ConfigurationException;

/**
 * Resolves credentials by handing the environment to a callback YOU provide,
 * and using whatever it returns. The package makes no assumptions about where
 * the credentials live or how they're shaped — you write the query (DB table,
 * external API, secrets manager, ...) in one place and return the four values.
 *
 * Configure `keycloak-sso.credentials` as either:
 *   - an invokable class name (resolved from the container), or
 *   - a closure: fn (string $environment) => array|ConnectionConfig
 *
 * The callback returns either a ConnectionConfig, or an array with keys
 * base_url, realm, client_id, client_secret.
 */
class CallbackConnectionResolver implements ConfigResolver
{
    public function __construct(
        protected Container $container,
        protected Config $config,
        protected ConfigConnectionResolver $envResolver,
    ) {}

    public function resolve(string $environment): ConnectionConfig
    {
        $callback = $this->config->get('keycloak-sso.credentials');

        // No callback configured at all → straight to the env connections.
        if ($callback === null) {
            return $this->envResolver->resolve($environment);
        }

        $fromCallback = fn () => $this->resolveFromCallback($callback, $environment);
        $fromEnv      = fn () => $this->envResolver->resolve($environment);

        // credentials_priority=true (default): callback wins, env is fallback.
        // credentials_priority=false: env wins, callback is fallback.
        [$primary, $secondary] = $this->config->get('keycloak-sso.credentials_priority', true)
            ? [$fromCallback, $fromEnv]
            : [$fromEnv, $fromCallback];

        // Use the primary source; fall back to the other only when the primary
        // has no usable config (missing/empty values).
        try {
            return $primary();
        } catch (ConfigurationException $e) {
            return $secondary();
        }
    }

    /**
     * Invoke the configured callback and normalise its return value.
     *
     * @param  callable|string  $callback
     */
    protected function resolveFromCallback($callback, string $environment): ConnectionConfig
    {
        if (is_string($callback)) {
            $callback = $this->container->make($callback);
        }

        if (! is_callable($callback)) {
            throw new ConfigurationException(
                '[keycloak-sso.credentials] must be a closure or an invokable class name.'
            );
        }

        $result = $callback($environment);

        if ($result instanceof ConnectionConfig) {
            return $result;
        }

        return ConnectionConfig::fromArray((array) $result);
    }
}
