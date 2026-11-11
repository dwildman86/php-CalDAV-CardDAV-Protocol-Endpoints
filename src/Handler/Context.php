<?php

declare(strict_types=1);

namespace GhostParse\Dav\Handler;

use GhostParse\Dav\Backend\AddressBookBackendInterface;
use GhostParse\Dav\Backend\CalendarBackendInterface;
use GhostParse\Dav\Backend\PrincipalBackendInterface;
use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\Routing\Router;
use GhostParse\Dav\Routing\Tree;

/**
 * Per-request state shared by the method handlers.
 */
final class Context
{
    public const SYNC_TOKEN_PREFIX = 'urn:ghostparse:sync:';

    public readonly PropertyResolver $properties;

    public function __construct(
        public readonly Router $router,
        public readonly Tree $tree,
        public readonly string $user,
        public readonly PrincipalBackendInterface $principals,
        public readonly ?CalendarBackendInterface $calendars,
        public readonly ?AddressBookBackendInterface $addressBooks,
        public readonly int $maxResourceSize,
    ) {
        $this->properties = new PropertyResolver($this);
    }

    public function calendars(): CalendarBackendInterface
    {
        return $this->calendars ?? throw new HttpException(404, 'CalDAV is not enabled');
    }

    public function addressBooks(): AddressBookBackendInterface
    {
        return $this->addressBooks ?? throw new HttpException(404, 'CardDAV is not enabled');
    }

    public static function syncToken(int $token): string
    {
        return self::SYNC_TOKEN_PREFIX . $token;
    }

    /**
     * Parse a sync-token URI; returns null when it is not one of ours.
     */
    public static function parseSyncToken(string $token): ?int
    {
        $token = trim($token);
        if (!str_starts_with($token, self::SYNC_TOKEN_PREFIX)) {
            return null;
        }
        $value = substr($token, strlen(self::SYNC_TOKEN_PREFIX));

        return ctype_digit($value) ? (int) $value : null;
    }
}
