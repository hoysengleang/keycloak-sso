<?php

namespace PhAb\KeycloakSso\Exceptions;

/**
 * Thrown when the SSO server cannot be reached at all (DNS failure, timeout,
 * connection refused).
 */
class SsoConnectionException extends KeycloakException
{
    protected $message = 'Cannot reach sign-in service. Check your connection.';
}
