<?php

declare(strict_types=1);

namespace GhostParse\Dav\Routing;

use GhostParse\Dav\Model\AddressBook;
use GhostParse\Dav\Model\Calendar;
use GhostParse\Dav\Model\CalendarObject;
use GhostParse\Dav\Model\Card;
use GhostParse\Dav\Model\Principal;

/**
 * A resource that exists, together with the entities backing it.
 */
final class Node
{
    public function __construct(
        public readonly Location $location,
        public readonly string $href,
        public readonly ?Principal $principal = null,
        public readonly ?Calendar $calendar = null,
        public readonly ?CalendarObject $calendarObject = null,
        public readonly ?AddressBook $addressBook = null,
        public readonly ?Card $card = null,
    ) {
    }

    public function type(): NodeType
    {
        return $this->location->type;
    }
}
