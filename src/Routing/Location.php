<?php

declare(strict_types=1);

namespace GhostParse\Dav\Routing;

/**
 * A resolved request path.
 */
final class Location
{
    public function __construct(
        public readonly NodeType $type,
        public readonly ?string $user = null,
        public readonly ?string $collection = null,
        public readonly ?string $object = null,
    ) {
    }

    /**
     * Decoded path segments relative to the base URI.
     *
     * @return list<string>
     */
    public function segments(): array
    {
        $root = match ($this->type) {
            NodeType::Root => [],
            NodeType::PrincipalCollection, NodeType::Principal => ['principals'],
            NodeType::CalendarRoot, NodeType::CalendarHome, NodeType::Calendar, NodeType::CalendarObject => ['calendars'],
            NodeType::AddressBookRoot, NodeType::AddressBookHome, NodeType::AddressBook, NodeType::Card => ['addressbooks'],
        };

        return array_values(array_merge(
            $root,
            array_filter([$this->user, $this->collection, $this->object], static fn (?string $s): bool => $s !== null),
        ));
    }
}
