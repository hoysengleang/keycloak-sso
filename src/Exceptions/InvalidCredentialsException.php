<?php

namespace PhAb\KeycloakSso\Exceptions;

/**
 * Thrown when Keycloak positively rejects the username/password
 * (invalid_grant / invalid_user_credentials) and no fallback accepted them.
 */
class InvalidCredentialsException extends KeycloakException
{
    protected $message = 'Incorrect username or password.';
}
