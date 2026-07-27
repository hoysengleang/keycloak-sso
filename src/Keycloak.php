<?php

namespace PhAb\KeycloakSso;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use PhAb\KeycloakSso\Contracts\ConfigResolver;
use PhAb\KeycloakSso\Data\ConnectionConfig;
use PhAb\KeycloakSso\Data\LoginResult;
use PhAb\KeycloakSso\Exceptions\InvalidCredentialsException;
use PhAb\KeycloakSso\Exceptions\SsoConnectionException;
use PhAb\KeycloakSso\Exceptions\SsoServiceException;
use Psr\Log\LoggerInterface;

/**
 * The Keycloak SSO service — everything the package does, in one place.
 *
 * Read it top to bottom:
 *   1. Public API      — login(), refresh(), logout(), userInfo(), config()
 *   2. Failure mapping — handleTokenFailure(), runFallback()
 *   3. HTTP calls      — the raw OpenID Connect endpoints
 *   4. Helpers         — logging, config resolution
 */
class Keycloak
{
    /**
     * Keycloak error codes that unambiguously mean "wrong username/password".
     *
     * @var list<string>
     */
    protected array $invalidCredentialErrors = ['invalid_grant', 'invalid_user_credentials', 'unauthorized'];

    public function __construct(
        protected HttpFactory $http,
        protected ConfigResolver $resolver,
        protected string $environment,
        protected string $scope = 'openid profile email',
        protected int $timeout = 10,
        protected ?LoggerInterface $logger = null,
        /** @var (callable(string, string): mixed)|null */
        protected $fallback = null,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    */

    /**
     * Authenticate a user by username/password.
     *
     * @param  string|null  $reference  Correlation id for logs; auto-generated when null.
     *
     * @throws InvalidCredentialsException  Credentials rejected and no fallback accepted them.
     * @throws SsoServiceException          SSO reachable but errored (5xx, bad userinfo, ...).
     * @throws SsoConnectionException       SSO unreachable (DNS/timeout/refused).
     * @throws \PhAb\KeycloakSso\Exceptions\ConfigurationException  Missing/invalid config.
     */
    public function login(string $username, string $password, ?string $reference = null): LoginResult
    {
        $reference = $reference ?: Str::upper(Str::random(8));
        $config = $this->config();

        $this->log('info', 'Keycloak login attempt.', ['reference' => $reference, 'username' => $username]);

        $tokenResponse = $this->requestToken($config, $username, $password);

        if (! $tokenResponse->successful()) {
            return $this->handleTokenFailure($tokenResponse, $username, $password, $reference);
        }

        $token = $tokenResponse->json();
        $accessToken = $token['access_token'] ?? null;

        if (! $accessToken) {
            $this->log('error', 'Keycloak token response missing access_token.', ['reference' => $reference]);
            throw new SsoServiceException(httpStatus: $tokenResponse->status());
        }

        $userInfoResponse = $this->requestUserInfo($config, $accessToken);

        if (! $userInfoResponse->successful()) {
            $this->log('error', 'Keycloak userinfo request failed.', [
                'reference' => $reference,
                'status'    => $userInfoResponse->status(),
            ]);
            throw new SsoServiceException(
                'Could not load your account. Please try again.',
                httpStatus: $userInfoResponse->status(),
            );
        }

        $this->log('info', 'Keycloak login successful.', [
            'reference' => $reference,
            'username'  => $username,
        ]);

        return new LoginResult(
            source: LoginResult::SOURCE_KEYCLOAK,
            user: $userInfoResponse->json(),
            token: $token,
        );
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @return array<string, mixed> The new raw token payload.
     *
     * @throws SsoServiceException
     */
    public function refresh(string $refreshToken): array
    {
        $config = $this->config();

        $response = $this->send(fn () => $this->http
            ->asForm()
            ->timeout($this->timeout)
            ->post($config->tokenUrl(), [
                'grant_type'    => 'refresh_token',
                'client_id'     => $config->clientId,
                'client_secret' => $config->clientSecret,
                'refresh_token' => $refreshToken,
            ]));

        if (! $response->successful()) {
            throw new SsoServiceException('Could not refresh session.', httpStatus: $response->status());
        }

        return $response->json();
    }

    /**
     * Revoke a Keycloak session by its refresh token.
     */
    public function logout(string $refreshToken): bool
    {
        $config = $this->config();

        return $this->send(fn () => $this->http
            ->asForm()
            ->timeout($this->timeout)
            ->post($config->logoutUrl(), [
                'client_id'     => $config->clientId,
                'client_secret' => $config->clientSecret,
                'refresh_token' => $refreshToken,
            ]))->successful();
    }

    /**
     * Fetch userinfo for an already-obtained access token.
     *
     * @return array<string, mixed>
     *
     * @throws SsoServiceException
     */
    public function userInfo(string $accessToken): array
    {
        $response = $this->requestUserInfo($this->config(), $accessToken);

        if (! $response->successful()) {
            throw new SsoServiceException(
                'Could not load your account. Please try again.',
                httpStatus: $response->status(),
            );
        }

        return $response->json();
    }

    /**
     * Resolve the connection config for the current environment.
     */
    public function config(): ConnectionConfig
    {
        return $this->resolver->resolve($this->environment);
    }

    /**
     * Register (or replace) the DB-password fallback at runtime.
     *
     * @param  (callable(string, string): mixed)|null  $fallback
     */
    public function fallbackUsing(?callable $fallback): static
    {
        $this->fallback = $fallback;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Failure mapping
    |--------------------------------------------------------------------------
    */

    /**
     * Decide what a non-2xx token response means and act accordingly.
     */
    protected function handleTokenFailure(Response $response, string $username, string $password, string $reference): LoginResult
    {
        $error = (string) ($response->json('error') ?? '');
        $isInvalidCredentials = in_array($response->status(), [400, 401], true)
            && in_array($error, $this->invalidCredentialErrors, true);

        $this->log('warning', 'Keycloak token request failed.', [
            'reference' => $reference,
            'username'  => $username,
            'status'    => $response->status(),
            'error'     => $error,
        ]);

        if (! $isInvalidCredentials) {
            // Reachable but not a credential problem => SSO-side error.
            throw new SsoServiceException(httpStatus: $response->status());
        }

        // Credentials rejected — give the application a chance to accept them locally.
        $fallbackUser = $this->runFallback($username, $password);

        if ($fallbackUser !== null) {
            $this->log('info', 'DB fallback login successful (Keycloak rejected credentials).', [
                'reference' => $reference,
                'username'  => $username,
            ]);

            return new LoginResult(source: LoginResult::SOURCE_FALLBACK, user: $fallbackUser);
        }

        throw new InvalidCredentialsException;
    }

    /**
     * Invoke the configured fallback, if any.
     */
    protected function runFallback(string $username, string $password): mixed
    {
        if ($this->fallback === null) {
            return null;
        }

        return ($this->fallback)($username, $password);
    }

    /*
    |--------------------------------------------------------------------------
    | Raw HTTP calls (OpenID Connect endpoints)
    |--------------------------------------------------------------------------
    */

    /**
     * Exchange username/password for tokens (Resource Owner Password grant).
     */
    protected function requestToken(ConnectionConfig $config, string $username, string $password): Response
    {
        return $this->send(fn () => $this->http
            ->asForm()
            ->timeout($this->timeout)
            ->post($config->tokenUrl(), [
                'grant_type'    => 'password',
                'client_id'     => $config->clientId,
                'client_secret' => $config->clientSecret,
                'username'      => $username,
                'password'      => $password,
                'scope'         => $this->scope,
            ]));
    }

    /**
     * Fetch the userinfo for a given access token.
     */
    protected function requestUserInfo(ConnectionConfig $config, string $accessToken): Response
    {
        return $this->send(fn () => $this->http
            ->withToken($accessToken)
            ->timeout($this->timeout)
            ->get($config->userInfoUrl()));
    }

    /**
     * Run an HTTP call, normalising connection-level failures.
     *
     * @param  callable():Response  $callback
     */
    protected function send(callable $callback): Response
    {
        try {
            return $callback();
        } catch (HttpConnectionException $e) {
            throw new SsoConnectionException($e->getMessage(), previous: $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->{$level}($message, $context);
    }
}
