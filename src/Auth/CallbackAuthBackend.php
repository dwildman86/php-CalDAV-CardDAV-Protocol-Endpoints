<?php

declare(strict_types=1);

namespace GhostParse\Dav\Auth;

/**
 * Delegates credential checks to a user-supplied callable, making it easy to
 * plug the server into an existing user database or identity provider.
 */
final class CallbackAuthBackend implements AuthBackendInterface
{
    /** @var callable(string, string): bool */
    private $callback;

    /**
     * @param callable(string, string): bool $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function authenticate(string $username, string $password): bool
    {
        return (bool) ($this->callback)($username, $password);
    }
}
