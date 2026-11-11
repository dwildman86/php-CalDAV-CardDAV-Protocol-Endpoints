<?php

declare(strict_types=1);

namespace GhostParse\Dav\Backend;

use GhostParse\Dav\Model\AddressBook;
use GhostParse\Dav\Model\Card;
use GhostParse\Dav\Model\ChangeSet;
use GhostParse\Dav\Model\ObjectMetadata;

/**
 * Storage for address books and vCards.
 *
 * Address book property arrays use the keys: displayName and description.
 */
interface AddressBookBackendInterface
{
    /**
     * @return list<AddressBook>
     */
    public function getAddressBooksForUser(string $username): array;

    public function getAddressBook(string $username, string $uri): ?AddressBook;

    /**
     * @param array<string, mixed> $properties
     */
    public function createAddressBook(string $username, string $uri, array $properties = []): void;

    /**
     * @param array<string, mixed> $properties Only the keys present are updated; null clears a value.
     */
    public function updateAddressBook(int|string $addressBookId, array $properties): void;

    public function deleteAddressBook(int|string $addressBookId): void;

    /**
     * @return list<Card>
     */
    public function getCards(int|string $addressBookId): array;

    public function getCard(int|string $addressBookId, string $uri): ?Card;

    public function findCardUriByUid(int|string $addressBookId, string $uid): ?string;

    /**
     * @return string The new ETag
     */
    public function createCard(int|string $addressBookId, string $uri, string $data, ObjectMetadata $metadata): string;

    /**
     * @return string The new ETag
     */
    public function updateCard(int|string $addressBookId, string $uri, string $data, ObjectMetadata $metadata): string;

    public function deleteCard(int|string $addressBookId, string $uri): void;

    /**
     * Return the changes since the given token, or null if the token is unknown.
     */
    public function getChangesSince(int|string $addressBookId, int $syncToken): ?ChangeSet;
}
