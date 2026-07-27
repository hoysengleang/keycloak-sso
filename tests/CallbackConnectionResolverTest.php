<?php

namespace PhAb\KeycloakSso\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PhAb\KeycloakSso\Contracts\ConfigResolver;
use PhAb\KeycloakSso\Data\ConnectionConfig;
use PhAb\KeycloakSso\Exceptions\ConfigurationException;
use PhAb\KeycloakSso\Facades\KeycloakSso;
use PhAb\KeycloakSso\Resolvers\CallbackConnectionResolver;

class CallbackConnectionResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('keycloak-sso.resolver', CallbackConnectionResolver::class);
    }

    public function test_resolves_from_a_closure_returning_an_array(): void
    {
        config()->set('keycloak-sso.credentials', fn (string $env) => [
            'base_url'      => "https://sso.$env.test",
            'realm'         => 'ph-ab',
            'client_id'     => 'ph-ab-api',
            'client_secret' => 'secret',
        ]);

        $config = app(ConfigResolver::class)->resolve('staging');

        $this->assertSame('https://sso.staging.test', $config->baseUrl);
        $this->assertSame('ph-ab-api', $config->clientId);
    }

    public function test_resolves_from_an_invokable_class_name(): void
    {
        config()->set('keycloak-sso.credentials', InvokableCredentials::class);

        $config = app(ConfigResolver::class)->resolve('production');

        $this->assertInstanceOf(ConnectionConfig::class, $config);
        $this->assertSame('https://invokable.test', $config->baseUrl);
    }

    public function test_callback_may_return_a_connection_config_directly(): void
    {
        config()->set('keycloak-sso.credentials', fn () => new ConnectionConfig(
            baseUrl: 'https://direct.test',
            realm: 'ph-ab',
            clientId: 'ph-ab-api',
            clientSecret: 'secret',
        ));

        $config = app(ConfigResolver::class)->resolve('local');

        $this->assertSame('https://direct.test', $config->baseUrl);
    }

    public function test_falls_back_to_env_connections_when_no_callback(): void
    {
        // No callback => the env-driven connections keep working (TestCase
        // seeds keycloak-sso.connections.default = https://sso.test/demo).
        config()->set('keycloak-sso.credentials', null);

        $config = app(ConfigResolver::class)->resolve('testing');

        $this->assertSame('https://sso.test', $config->baseUrl);
        $this->assertSame('demo', $config->realm);
    }

    public function test_env_wins_when_priority_is_disabled(): void
    {
        // Callback returns one thing, .env another. With priority off and a
        // complete env connection, .env must win.
        config()->set('keycloak-sso.credentials_priority', false);
        config()->set('keycloak-sso.credentials', fn () => [
            'base_url'      => 'https://from-callback.test',
            'realm'         => 'cb',
            'client_id'     => 'cb',
            'client_secret' => 'cb',
        ]);

        $config = app(ConfigResolver::class)->resolve('testing'); // env default = sso.test

        $this->assertSame('https://sso.test', $config->baseUrl);
    }

    public function test_callback_fills_the_gap_when_env_is_incomplete(): void
    {
        // env "production" connection exists in package config but its values
        // are null here, so env is incomplete → callback fills the gap even
        // though env has priority.
        config()->set('keycloak-sso.credentials_priority', false);
        config()->set('keycloak-sso.credentials', fn () => [
            'base_url'      => 'https://from-callback.test',
            'realm'         => 'cb',
            'client_id'     => 'cb',
            'client_secret' => 'cb',
        ]);

        $config = app(ConfigResolver::class)->resolve('production');

        $this->assertSame('https://from-callback.test', $config->baseUrl);
    }

    public function test_real_key_value_table_query(): void
    {
        Schema::create('system_configs', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 50);
            $table->string('group_name', 100);
            $table->string('key_name', 191);
            $table->text('value');
            $table->unique(['environment', 'group_name', 'key_name']);
        });

        foreach ([
            'KEYCLOAK_BASE_URL'      => 'https://sso.example.com',
            'KEYCLOAK_REALM'         => 'ph-ab',
            'KEYCLOAK_CLIENT_ID'     => 'ph-ab-api',
            'KEYCLOAK_CLIENT_SECRET' => 'plain-secret',
        ] as $key => $value) {
            DB::table('system_configs')->insert([
                'environment' => 'testing', // Testbench's app environment
                'group_name'  => 'KEYCLOAK',
                'key_name'    => $key,
                'value'       => $value,
            ]);
        }

        // The exact query a consumer would write in config('keycloak-sso.credentials').
        config()->set('keycloak-sso.credentials_priority', true); // callback wins over the seeded env default
        config()->set('keycloak-sso.credentials', function (string $environment) {
            $rows = DB::table('system_configs')
                ->where('group_name', 'KEYCLOAK')
                ->where('environment', $environment)
                ->pluck('value', 'key_name');

            return [
                'base_url'      => $rows['KEYCLOAK_BASE_URL'] ?? null,
                'realm'         => $rows['KEYCLOAK_REALM'] ?? null,
                'client_id'     => $rows['KEYCLOAK_CLIENT_ID'] ?? null,
                'client_secret' => $rows['KEYCLOAK_CLIENT_SECRET'] ?? null,
            ];
        });

        Http::fake([
            'https://sso.example.com/realms/ph-ab/protocol/openid-connect/token'    => Http::response(['access_token' => 'abc'], 200),
            'https://sso.example.com/realms/ph-ab/protocol/openid-connect/userinfo' => Http::response(['preferred_username' => 'jane'], 200),
        ]);

        $result = KeycloakSso::login('jane', 'secret');

        $this->assertTrue($result->fromKeycloak());
        $this->assertSame('jane', $result->user['preferred_username']);
    }
}

class InvokableCredentials
{
    public function __invoke(string $environment): array
    {
        return [
            'base_url'      => 'https://invokable.test',
            'realm'         => 'ph-ab',
            'client_id'     => 'ph-ab-api',
            'client_secret' => 'secret',
        ];
    }
}
