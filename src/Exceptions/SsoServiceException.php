<?php

namespace PhAb\KeycloakSso\Exceptions;

/**
 * Thrown when the SSO server is reachable but returns an unexpected/erroneous
 * response (5xx, malformed payload, userinfo failure, etc.). Distinct from
 * invalid credentials — this is a service-side problem, not a user error.
 */
class SsoServiceException extends KeycloakException
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        string $message = 'Sign-in service (SSO) error. Please try again.',
        public readonly ?int $httpStatus = null,
        public readonly ?array $context = null,
    ) {
        parent::__construct($message);
    }
}
