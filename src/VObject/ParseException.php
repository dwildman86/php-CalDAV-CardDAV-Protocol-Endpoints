<?php

declare(strict_types=1);

namespace GhostParse\Dav\VObject;

/**
 * Thrown when iCalendar / vCard data is syntactically invalid or does not
 * satisfy the requirements for being stored in a collection.
 */
final class ParseException extends \RuntimeException
{
}
