<?php

declare(strict_types=1);

namespace GhostParse\Dav\Routing;

use GhostParse\Dav\Backend\AddressBookBackendInterface;
use GhostParse\Dav\Backend\CalendarBackendInterface;
use GhostParse\Dav\Backend\PrincipalBackendInterface;
use GhostParse\Dav\Model\Principal;

/**
 * Loads nodes and their children from the backends.
 */
final class Tree
{
    public function __construct(
        private Router $router,
        private PrincipalBackendInterface $principals,
        private ?CalendarBackendInterface $calendars,
        private ?AddressBookBackendInterface $addressBooks,
        private string $currentUser,
    ) {
    }

    public function get(Location $location): ?Node
    {
        $href = $this->router->href($location);

        switch ($location->type) {
            case NodeType::Root:
            case NodeType::PrincipalCollection:
            case NodeType::CalendarRoot:
            case NodeType::AddressBookRoot:
                return new Node($location, $href);

            case NodeType::Principal:
            case NodeType::CalendarHome:
            case NodeType::AddressBookHome:
                $principal = $this->principal((string) $location->user);

                return $principal === null ? null : new Node($location, $href, principal: $principal);

            case NodeType::Calendar:
                $calendar = $this->calendars?->getCalendar((string) $location->user, (string) $location->collection);

                return $calendar === null ? null : new Node($location, $href, calendar: $calendar);

            case NodeType::CalendarObject:
                $calendar = $this->calendars?->getCalendar((string) $location->user, (string) $location->collection);
                if ($calendar === null) {
                    return null;
                }
                $object = $this->calendars?->getCalendarObject($calendar->id, (string) $location->object);

                return $object === null ? null : new Node($location, $href, calendar: $calendar, calendarObject: $object);

            case NodeType::AddressBook:
                $book = $this->addressBooks?->getAddressBook((string) $location->user, (string) $location->collection);

                return $book === null ? null : new Node($location, $href, addressBook: $book);

            case NodeType::Card:
                $book = $this->addressBooks?->getAddressBook((string) $location->user, (string) $location->collection);
                if ($book === null) {
                    return null;
                }
                $card = $this->addressBooks?->getCard($book->id, (string) $location->object);

                return $card === null ? null : new Node($location, $href, addressBook: $book, card: $card);
        }

        return null;
    }

    /**
     * @return list<Node>
     */
    public function children(Node $node): array
    {
        $children = [];
        $location = $node->location;

        switch ($location->type) {
            case NodeType::Root:
                $locations = [new Location(NodeType::PrincipalCollection)];
                if ($this->calendars !== null) {
                    $locations[] = new Location(NodeType::CalendarRoot);
                }
                if ($this->addressBooks !== null) {
                    $locations[] = new Location(NodeType::AddressBookRoot);
                }
                foreach ($locations as $child) {
                    $children[] = $this->get($child);
                }
                break;

            case NodeType::PrincipalCollection:
                $children[] = $this->get(new Location(NodeType::Principal, $this->currentUser));
                break;

            case NodeType::CalendarRoot:
                $children[] = $this->get(new Location(NodeType::CalendarHome, $this->currentUser));
                break;

            case NodeType::AddressBookRoot:
                $children[] = $this->get(new Location(NodeType::AddressBookHome, $this->currentUser));
                break;

            case NodeType::CalendarHome:
                foreach ($this->calendars?->getCalendarsForUser((string) $location->user) ?? [] as $calendar) {
                    $child = new Location(NodeType::Calendar, $location->user, $calendar->uri);
                    $children[] = new Node($child, $this->router->href($child), calendar: $calendar);
                }
                break;

            case NodeType::Calendar:
                if ($node->calendar !== null) {
                    foreach ($this->calendars?->getCalendarObjects($node->calendar->id) ?? [] as $object) {
                        $child = new Location(NodeType::CalendarObject, $location->user, $location->collection, $object->uri);
                        $children[] = new Node($child, $this->router->href($child), calendar: $node->calendar, calendarObject: $object);
                    }
                }
                break;

            case NodeType::AddressBookHome:
                foreach ($this->addressBooks?->getAddressBooksForUser((string) $location->user) ?? [] as $book) {
                    $child = new Location(NodeType::AddressBook, $location->user, $book->uri);
                    $children[] = new Node($child, $this->router->href($child), addressBook: $book);
                }
                break;

            case NodeType::AddressBook:
                if ($node->addressBook !== null) {
                    foreach ($this->addressBooks?->getCards($node->addressBook->id) ?? [] as $card) {
                        $child = new Location(NodeType::Card, $location->user, $location->collection, $card->uri);
                        $children[] = new Node($child, $this->router->href($child), addressBook: $node->addressBook, card: $card);
                    }
                }
                break;

            default:
                break;
        }

        return array_values(array_filter($children));
    }

    private function principal(string $username): ?Principal
    {
        $principal = $this->principals->getPrincipal($username);
        if ($principal === null && $username === $this->currentUser) {
            // Authenticated users always have a principal, even when the
            // principal backend knows nothing about them.
            return new Principal($username);
        }

        return $principal;
    }
}
