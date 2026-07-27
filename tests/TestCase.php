<?php

namespace PhAb\KeycloakSso\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PhAb\KeycloakSso\KeycloakSsoServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [KeycloakSsoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('keycloak-sso.log_channel', null);
        $app['config']->set('keycloak-sso.connections.default', [
            'base_url'      => 'https://sso.test',
            'realm'         => 'demo',
            'client_id'     => 'demo-client',
            'client_secret' => 'demo-secret',
        ]);
    }
}
