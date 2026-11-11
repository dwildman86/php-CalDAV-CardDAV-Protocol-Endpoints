<?php

declare(strict_types=1);

namespace GhostParse\Dav\Routing;

/**
 * Maps request paths to locations and locations back to hrefs.
 */
final class Router
{
    private string $baseUri;

    public function __construct(
        string $baseUri = '/',
        private bool $calendarsEnabled = true,
        private bool $addressBooksEnabled = true,
    ) {
        $this->baseUri = '/' . trim($baseUri, '/') . '/';
        if ($this->baseUri === '//') {
            $this->baseUri = '/';
        }
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }

    /**
     * Resolve a (percent-encoded) request path. Returns null for paths that
     * do not map to a resource type.
     */
    public function resolve(string $path): ?Location
    {
        if ($path . '/' === $this->baseUri) {
            $path .= '/';
        }
        if (!str_starts_with($path, $this->baseUri)) {
            return null;
        }

        $relative = substr($path, strlen($this->baseUri));
        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..' || str_contains($decoded, '/') || str_contains($decoded, "\0")) {
                return null;
            }
            $segments[] = $decoded;
        }

        $count = count($segments);
        if ($count === 0) {
            return new Location(NodeType::Root);
        }

        [$root, $user, $collection, $object] = array_pad($segments, 4, null);
        if ($count > 4) {
            return null;
        }

        switch ($root) {
            case 'principals':
                return match ($count) {
                    1 => new Location(NodeType::PrincipalCollection),
                    2 => new Location(NodeType::Principal, $user),
                    default => null,
                };

            case 'calendars':
                if (!$this->calendarsEnabled) {
                    return null;
                }

                return match ($count) {
                    1 => new Location(NodeType::CalendarRoot),
                    2 => new Location(NodeType::CalendarHome, $user),
                    3 => new Location(NodeType::Calendar, $user, $collection),
                    default => new Location(NodeType::CalendarObject, $user, $collection, $object),
                };

            case 'addressbooks':
                if (!$this->addressBooksEnabled) {
                    return null;
                }

                return match ($count) {
                    1 => new Location(NodeType::AddressBookRoot),
                    2 => new Location(NodeType::AddressBookHome, $user),
                    3 => new Location(NodeType::AddressBook, $user, $collection),
                    default => new Location(NodeType::Card, $user, $collection, $object),
                };
        }

        return null;
    }

    /**
     * Resolve an href from a request body (absolute URL or absolute path).
     */
    public function resolveHref(string $href): ?Location
    {
        $href = trim($href);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
            $path = parse_url($href, PHP_URL_PATH);
            $href = is_string($path) ? $path : '/';
        }

        return $this->resolve($href);
    }

    public function href(Location $location): string
    {
        $segments = array_map('rawurlencode', $location->segments());
        $path = $this->baseUri . implode('/', $segments);
        if ($segments !== [] && $location->type->isCollection()) {
            $path .= '/';
        }

        return $path;
    }

    public function principalHref(string $username): string
    {
        return $this->href(new Location(NodeType::Principal, $username));
    }

    public function isCalendarsEnabled(): bool
    {
        return $this->calendarsEnabled;
    }

    public function isAddressBooksEnabled(): bool
    {
        return $this->addressBooksEnabled;
    }
}
