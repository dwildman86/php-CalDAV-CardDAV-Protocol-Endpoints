<?php

declare(strict_types=1);

namespace GhostParse\Dav\Auth;

/**
 * Verifies credentials supplied through HTTP Basic authentication.
 */
interface AuthBackendInterface
{
    /**
     * Return true when the username / password combination is valid.
     */
    public function authenticate(string $username, string $password): bool;
}
