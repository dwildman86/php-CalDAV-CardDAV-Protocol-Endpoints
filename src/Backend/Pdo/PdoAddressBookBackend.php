<?php

declare(strict_types=1);

namespace GhostParse\Dav\Backend\Pdo;

use GhostParse\Dav\Backend\AddressBookBackendInterface;
use GhostParse\Dav\Model\AddressBook;
use GhostParse\Dav\Model\Card;
use GhostParse\Dav\Model\ChangeSet;
use GhostParse\Dav\Model\ObjectMetadata;
use PDO;

/**
 * PDO (SQLite / MySQL / PostgreSQL) address book storage.
 */
final class PdoAddressBookBackend extends AbstractPdoBackend implements AddressBookBackendInterface
{
    private const FIELD_MAP = [
        'displayName' => 'displayname',
        'description' => 'description',
    ];

    private const CARD_COLUMNS = 'addressbookid, uri, data, etag, size, lastmodified';

    public function getAddressBooksForUser(string $username): array
    {
        $rows = $this->query(
            sprintf('SELECT * FROM %s WHERE owner = ? ORDER BY id', $this->table('addressbooks')),
            [$username],
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): AddressBook => $this->hydrateAddressBook($row), $rows);
    }

    public function getAddressBook(string $username, string $uri): ?AddressBook
    {
        $row = $this->query(
            sprintf('SELECT * FROM %s WHERE owner = ? AND uri = ?', $this->table('addressbooks')),
            [$username, $uri],
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateAddressBook($row);
    }

    public function createAddressBook(string $username, string $uri, array $properties = []): void
    {
        $values = $this->mapProperties($properties);
        $values['owner'] = $username;
        $values['uri'] = $uri;
        $values['synctoken'] = 1;

        $columns = array_keys($values);
        $this->query(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->table('addressbooks'),
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?')),
            ),
            array_values($values),
        );
    }

    public function updateAddressBook(int|string $addressBookId, array $properties): void
    {
        $values = $this->mapProperties($properties);
        if ($values === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $params = array_values($values);
        $params[] = $addressBookId;
        $this->query(sprintf('UPDATE %s SET %s WHERE id = ?', $this->table('addressbooks'), $sets), $params);
    }

    public function deleteAddressBook(int|string $addressBookId): void
    {
        $this->transaction(function () use ($addressBookId): void {
            $this->query(sprintf('DELETE FROM %s WHERE addressbookid = ?', $this->table('cards')), [$addressBookId]);
            $this->query(sprintf('DELETE FROM %s WHERE addressbookid = ?', $this->table('addressbookchanges')), [$addressBookId]);
            $this->query(sprintf('DELETE FROM %s WHERE id = ?', $this->table('addressbooks')), [$addressBookId]);
        });
    }

    public function getCards(int|string $addressBookId): array
    {
        $rows = $this->query(
            sprintf('SELECT %s FROM %s WHERE addressbookid = ? ORDER BY id', self::CARD_COLUMNS, $this->table('cards')),
            [$addressBookId],
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): Card => $this->hydrateCard($row), $rows);
    }

    public function getCard(int|string $addressBookId, string $uri): ?Card
    {
        $row = $this->query(
            sprintf('SELECT %s FROM %s WHERE addressbookid = ? AND uri = ?', self::CARD_COLUMNS, $this->table('cards')),
            [$addressBookId, $uri],
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateCard($row);
    }

    public function findCardUriByUid(int|string $addressBookId, string $uid): ?string
    {
        $uri = $this->query(
            sprintf('SELECT uri FROM %s WHERE addressbookid = ? AND uid = ?', $this->table('cards')),
            [$addressBookId, $uid],
        )->fetchColumn();

        return $uri === false ? null : (string) $uri;
    }

    public function createCard(int|string $addressBookId, string $uri, string $data, ObjectMetadata $metadata): string
    {
        $etag = self::etag($data);
        $this->transaction(function () use ($addressBookId, $uri, $data, $metadata, $etag): void {
            $this->query(
                sprintf('INSERT INTO %s (addressbookid, uri, data, etag, size, lastmodified, uid) VALUES (?, ?, ?, ?, ?, ?, ?)', $this->table('cards')),
                [$addressBookId, $uri, $data, $etag, strlen($data), time(), $metadata->uid],
            );
            $this->recordChange('addressbooks', 'addressbookchanges', 'addressbookid', $addressBookId, $uri, self::OP_ADD);
        });

        return $etag;
    }

    public function updateCard(int|string $addressBookId, string $uri, string $data, ObjectMetadata $metadata): string
    {
        $etag = self::etag($data);
        $this->transaction(function () use ($addressBookId, $uri, $data, $metadata, $etag): void {
            $this->query(
                sprintf('UPDATE %s SET data = ?, etag = ?, size = ?, lastmodified = ?, uid = ? WHERE addressbookid = ? AND uri = ?', $this->table('cards')),
                [$data, $etag, strlen($data), time(), $metadata->uid, $addressBookId, $uri],
            );
            $this->recordChange('addressbooks', 'addressbookchanges', 'addressbookid', $addressBookId, $uri, self::OP_MODIFY);
        });

        return $etag;
    }

    public function deleteCard(int|string $addressBookId, string $uri): void
    {
        $this->transaction(function () use ($addressBookId, $uri): void {
            $this->query(sprintf('DELETE FROM %s WHERE addressbookid = ? AND uri = ?', $this->table('cards')), [$addressBookId, $uri]);
            $this->recordChange('addressbooks', 'addressbookchanges', 'addressbookid', $addressBookId, $uri, self::OP_DELETE);
        });
    }

    public function getChangesSince(int|string $addressBookId, int $syncToken): ?ChangeSet
    {
        return $this->changesSince('addressbooks', 'addressbookchanges', 'addressbookid', $addressBookId, $syncToken);
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function mapProperties(array $properties): array
    {
        $values = [];
        foreach ($properties as $key => $value) {
            if (isset(self::FIELD_MAP[$key])) {
                $values[self::FIELD_MAP[$key]] = $value === null ? null : (string) $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAddressBook(array $row): AddressBook
    {
        return new AddressBook(
            id: is_numeric($row['id']) ? (int) $row['id'] : (string) $row['id'],
            owner: (string) $row['owner'],
            uri: (string) $row['uri'],
            displayName: $row['displayname'] !== null ? (string) $row['displayname'] : null,
            description: $row['description'] !== null ? (string) $row['description'] : null,
            syncToken: (int) $row['synctoken'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCard(array $row): Card
    {
        return new Card(
            addressBookId: is_numeric($row['addressbookid']) ? (int) $row['addressbookid'] : (string) $row['addressbookid'],
            uri: (string) $row['uri'],
            data: self::readData($row['data']),
            etag: (string) $row['etag'],
            size: (int) $row['size'],
            lastModified: (int) $row['lastmodified'],
        );
    }
}
