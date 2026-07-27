<?php

namespace PhAb\KeycloakSso;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use PhAb\KeycloakSso\Contracts\ConfigResolver;
use PhAb\KeycloakSso\Resolvers\ConfigConnectionResolver;

class KeycloakSsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/keycloak-sso.php', 'keycloak-sso');
        $this->app->bind(ConfigResolver::class, function (Application $app) {
            $resolver = $app['config']->get('keycloak-sso.resolver', ConfigConnectionResolver::class);
            return $app->make($resolver);
        });
        $this->app->singleton(Keycloak::class, function (Application $app) {
            $config  = $app['config'];
            $channel = $config->get('keycloak-sso.log_channel');
            $logger  = $channel ? $app['log']->channel($channel) : null;

            return new Keycloak(
                http       : $app->make(HttpFactory::class),
                resolver   : $app->make(ConfigResolver::class),
                environment: $app->environment(),
                scope      : (string) $config->get('keycloak-sso.scope', 'openid profile email'),
                timeout    : (int) $config->get('keycloak-sso.timeout', 10),
                logger     : $logger,
                fallback   : $this->resolveFallback($app),
            );
        });

        $this->app->alias(Keycloak::class, 'keycloak-sso');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/keycloak-sso.php' => $this->app->configPath('keycloak-sso.php'),
            ], 'keycloak-sso-config');
        }
    }

    /**
     * Turn the config('keycloak-sso.fallback') value into a callable, if set.
     */
    protected function resolveFallback(Application $app): ?callable
    {
        $fallback = $app['config']->get('keycloak-sso.fallback');

        if ($fallback === null) {
            return null;
        }

        if (is_callable($fallback)) {
            return $fallback;
        }

        // A class name resolved from the container (must be invokable).
        $instance = $app->make($fallback);

        return is_callable($instance) ? $instance : null;
    }
}
