<?php

declare(strict_types=1);

namespace GhostParse\Dav\Routing;

/**
 * The kinds of resources exposed by the server.
 *
 *   /                                       Root
 *   /principals/                            PrincipalCollection
 *   /principals/{user}/                     Principal
 *   /calendars/                             CalendarRoot
 *   /calendars/{user}/                      CalendarHome
 *   /calendars/{user}/{calendar}/           Calendar
 *   /calendars/{user}/{calendar}/{object}   CalendarObject
 *   /addressbooks/                          AddressBookRoot
 *   /addressbooks/{user}/                   AddressBookHome
 *   /addressbooks/{user}/{book}/            AddressBook
 *   /addressbooks/{user}/{book}/{card}      Card
 */
enum NodeType
{
    case Root;
    case PrincipalCollection;
    case Principal;
    case CalendarRoot;
    case CalendarHome;
    case Calendar;
    case CalendarObject;
    case AddressBookRoot;
    case AddressBookHome;
    case AddressBook;
    case Card;

    public function isCollection(): bool
    {
        return $this !== self::CalendarObject && $this !== self::Card;
    }

    /**
     * Whether the resource belongs to a specific user (and is private to them).
     */
    public function isUserScoped(): bool
    {
        return in_array($this, [
            self::CalendarHome, self::Calendar, self::CalendarObject,
            self::AddressBookHome, self::AddressBook, self::Card,
        ], true);
    }
}
