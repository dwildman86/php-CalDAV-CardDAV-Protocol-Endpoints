<?php

declare(strict_types=1);

namespace GhostParse\Dav\Backend\Pdo;

use GhostParse\Dav\Model\ChangeSet;
use PDO;
use PDOStatement;

/**
 * Shared helpers for the PDO backends.
 */
abstract class AbstractPdoBackend
{
    protected const OP_ADD = 1;
    protected const OP_MODIFY = 2;
    protected const OP_DELETE = 3;

    public function __construct(
        protected PDO $pdo,
        protected string $tablePrefix = '',
    ) {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $tablePrefix)) {
            throw new \InvalidArgumentException('Table prefix may only contain letters, digits and underscores');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    protected function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    /**
     * Run a callback inside a transaction (re-using an outer one if active).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Read a value that may be returned by the driver as a stream (BLOB columns).
     */
    protected static function readData(mixed $value): string
    {
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }

        return (string) $value;
    }

    protected static function etag(string $data): string
    {
        return '"' . md5($data) . '"';
    }

    /**
     * Record a change and bump the collection's sync token.
     */
    protected function recordChange(string $collectionTable, string $changesTable, string $foreignKey, int|string $collectionId, string $uri, int $operation): void
    {
        $token = $this->query(
            sprintf('SELECT synctoken FROM %s WHERE id = ?', $this->table($collectionTable)),
            [$collectionId],
        )->fetchColumn();
        if ($token === false) {
            return;
        }
        $this->query(
            sprintf('INSERT INTO %s (%s, uri, synctoken, operation) VALUES (?, ?, ?, ?)', $this->table($changesTable), $foreignKey),
            [$collectionId, $uri, (int) $token, $operation],
        );
        $this->query(
            sprintf('UPDATE %s SET synctoken = synctoken + 1 WHERE id = ?', $this->table($collectionTable)),
            [$collectionId],
        );
    }

    protected function changesSince(string $collectionTable, string $changesTable, string $foreignKey, int|string $collectionId, int $syncToken): ?ChangeSet
    {
        $current = $this->query(
            sprintf('SELECT synctoken FROM %s WHERE id = ?', $this->table($collectionTable)),
            [$collectionId],
        )->fetchColumn();
        if ($current === false) {
            return null;
        }
        $current = (int) $current;
        if ($syncToken < 1 || $syncToken > $current) {
            return null;
        }

        $rows = $this->query(
            sprintf('SELECT uri, operation FROM %s WHERE %s = ? AND synctoken >= ? AND synctoken < ? ORDER BY id', $this->table($changesTable), $foreignKey),
            [$collectionId, $syncToken, $current],
        )->fetchAll(PDO::FETCH_ASSOC);

        $state = [];
        foreach ($rows as $row) {
            $state[(string) $row['uri']] = (int) $row['operation'] === self::OP_DELETE ? 'deleted' : 'changed';
        }

        $changed = [];
        $deleted = [];
        foreach ($state as $uri => $kind) {
            if ($kind === 'deleted') {
                $deleted[] = (string) $uri;
            } else {
                $changed[] = (string) $uri;
            }
        }

        return new ChangeSet($current, $changed, $deleted);
    }
}
