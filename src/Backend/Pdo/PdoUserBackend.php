<?php

declare(strict_types=1);

namespace GhostParse\Dav\Backend\Pdo;

use GhostParse\Dav\Auth\AuthBackendInterface;
use GhostParse\Dav\Backend\PrincipalBackendInterface;
use GhostParse\Dav\Model\Principal;
use PDO;

/**
 * PDO user store acting as both authentication and principal backend.
 * Passwords are stored with password_hash().
 */
final class PdoUserBackend extends AbstractPdoBackend implements AuthBackendInterface, PrincipalBackendInterface
{
    public function authenticate(string $username, string $password): bool
    {
        $hash = $this->query(
            sprintf('SELECT password_hash FROM %s WHERE username = ?', $this->table('users')),
            [$username],
        )->fetchColumn();

        if ($hash === false) {
            // Spend comparable time to make user enumeration via timing harder.
            static $dummy = null;
            $dummy ??= password_hash(random_bytes(16), PASSWORD_DEFAULT);
            password_verify($password, $dummy);

            return false;
        }

        if (!password_verify($password, (string) $hash)) {
            return false;
        }

        if (password_needs_rehash((string) $hash, PASSWORD_DEFAULT)) {
            $this->setPassword($username, $password);
        }

        return true;
    }

    public function getPrincipal(string $username): ?Principal
    {
        $row = $this->query(
            sprintf('SELECT username, displayname, email FROM %s WHERE username = ?', $this->table('users')),
            [$username],
        )->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new Principal(
            (string) $row['username'],
            $row['displayname'] !== null ? (string) $row['displayname'] : null,
            $row['email'] !== null ? (string) $row['email'] : null,
        );
    }

    public function createUser(string $username, string $password, ?string $displayName = null, ?string $email = null): void
    {
        self::assertValidUsername($username);
        $this->query(
            sprintf('INSERT INTO %s (username, password_hash, displayname, email) VALUES (?, ?, ?, ?)', $this->table('users')),
            [$username, password_hash($password, PASSWORD_DEFAULT), $displayName, $email],
        );
    }

    public function setPassword(string $username, string $password): void
    {
        $this->query(
            sprintf('UPDATE %s SET password_hash = ? WHERE username = ?', $this->table('users')),
            [password_hash($password, PASSWORD_DEFAULT), $username],
        );
    }

    public function updateUser(string $username, ?string $displayName, ?string $email): void
    {
        $this->query(
            sprintf('UPDATE %s SET displayname = ?, email = ? WHERE username = ?', $this->table('users')),
            [$displayName, $email, $username],
        );
    }

    public function deleteUser(string $username): void
    {
        $this->query(sprintf('DELETE FROM %s WHERE username = ?', $this->table('users')), [$username]);
    }

    public function userExists(string $username): bool
    {
        return $this->getPrincipal($username) !== null;
    }

    /**
     * @return list<string>
     */
    public function listUsers(): array
    {
        return array_map(
            'strval',
            $this->query(sprintf('SELECT username FROM %s ORDER BY username', $this->table('users')))->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public static function assertValidUsername(string $username): void
    {
        if ($username === '' || strlen($username) > 190 || preg_match('#[/:\\\\\x00-\x1f]#', $username)) {
            throw new \InvalidArgumentException('Usernames must be 1-190 characters and may not contain "/", ":", "\\" or control characters');
        }
    }
}
