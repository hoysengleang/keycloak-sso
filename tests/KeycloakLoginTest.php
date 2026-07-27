<?php

namespace PhAb\KeycloakSso\Tests;

use Illuminate\Support\Facades\Http;
use PhAb\KeycloakSso\Data\LoginResult;
use PhAb\KeycloakSso\Exceptions\InvalidCredentialsException;
use PhAb\KeycloakSso\Exceptions\SsoConnectionException;
use PhAb\KeycloakSso\Exceptions\SsoServiceException;
use PhAb\KeycloakSso\Facades\KeycloakSso;

class KeycloakLoginTest extends TestCase
{
    private string $tokenUrl = 'https://sso.test/realms/demo/protocol/openid-connect/token';

    private string $userInfoUrl = 'https://sso.test/realms/demo/protocol/openid-connect/userinfo';

    public function test_successful_login_returns_token_and_userinfo(): void
    {
        Http::fake([
            $this->tokenUrl => Http::response(['access_token' => 'abc', 'refresh_token' => 'ref'], 200),
            $this->userInfoUrl => Http::response(['preferred_username' => 'john', 'email' => 'john@test.dev'], 200),
        ]);

        $result = KeycloakSso::login('john', 'secret');

        $this->assertInstanceOf(LoginResult::class, $result);
        $this->assertTrue($result->fromKeycloak());
        $this->assertSame('abc', $result->accessToken());
        $this->assertSame('john', $result->user['preferred_username']);
    }

    public function test_invalid_credentials_throws_without_fallback(): void
    {
        Http::fake([
            $this->tokenUrl => Http::response(['error' => 'invalid_grant'], 401),
        ]);

        $this->expectException(InvalidCredentialsException::class);

        KeycloakSso::login('john', 'wrong');
    }

    public function test_invalid_credentials_uses_fallback_when_present(): void
    {
        Http::fake([
            $this->tokenUrl => Http::response(['error' => 'invalid_grant'], 401),
        ]);

        KeycloakSso::fallbackUsing(fn (string $u, string $p) => $u === 'john' && $p === 'db-pass'
            ? ['id' => 7, 'username' => $u]
            : null);

        $result = KeycloakSso::login('john', 'db-pass');

        $this->assertTrue($result->fromFallback());
        $this->assertSame(7, $result->user['id']);
        $this->assertNull($result->token);
    }

    public function test_server_error_maps_to_sso_service_exception(): void
    {
        Http::fake([
            $this->tokenUrl => Http::response(['error' => 'server_error'], 500),
        ]);

        $this->expectException(SsoServiceException::class);

        KeycloakSso::login('john', 'secret');
    }

    public function test_userinfo_failure_maps_to_sso_service_exception(): void
    {
        Http::fake([
            $this->tokenUrl => Http::response(['access_token' => 'abc'], 200),
            $this->userInfoUrl => Http::response('nope', 500),
        ]);

        $this->expectException(SsoServiceException::class);

        KeycloakSso::login('john', 'secret');
    }

    public function test_connection_failure_maps_to_connection_exception(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: could not resolve host');
        });

        $this->expectException(SsoConnectionException::class);

        KeycloakSso::login('john', 'secret');
    }
}
