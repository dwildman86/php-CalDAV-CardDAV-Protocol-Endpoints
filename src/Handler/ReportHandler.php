<?php

declare(strict_types=1);

namespace GhostParse\Dav\Handler;

use DateTimeZone;
use DOMElement;
use GhostParse\Dav\Filter\AddressBookQueryFilter;
use GhostParse\Dav\Filter\CalendarQueryFilter;
use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\Http\Request;
use GhostParse\Dav\Http\Response;
use GhostParse\Dav\Routing\Location;
use GhostParse\Dav\Routing\Node;
use GhostParse\Dav\Routing\NodeType;
use GhostParse\Dav\VObject\ParseException;
use GhostParse\Dav\VObject\Parser;
use GhostParse\Dav\Xml\MultiStatus;
use GhostParse\Dav\Xml\Ns;
use GhostParse\Dav\Xml\XmlUtil;

/**
 * REPORT: calendar-query, calendar-multiget, addressbook-query,
 * addressbook-multiget and sync-collection.
 */
final class ReportHandler
{
    private const CAL = '{' . Ns::CALDAV . '}';
    private const CARD = '{' . Ns::CARDDAV . '}';

    public function __construct(private Context $context)
    {
    }

    public function handle(Request $request, Location $location): Response
    {
        $node = $this->context->tree->get($location) ?? throw new HttpException(404, 'Not Found');
        $root = XmlUtil::parse($request->getBody())->documentElement;
        if ($root === null) {
            throw new HttpException(400, 'Empty REPORT body');
        }
        $minimal = PropfindHandler::wantsMinimal($request);

        $report = XmlUtil::clark($root);
        $isCalendar = $location->type === NodeType::Calendar || $location->type === NodeType::CalendarObject;
        $isAddressBook = $location->type === NodeType::AddressBook || $location->type === NodeType::Card;

        return match (true) {
            $report === self::CAL . 'calendar-multiget' && $isCalendar => $this->multiget($root, $node, $minimal, true),
            $report === self::CAL . 'calendar-query' && $isCalendar => $this->calendarQuery($request, $root, $node, $minimal),
            $report === self::CARD . 'addressbook-multiget' && $isAddressBook => $this->multiget($root, $node, $minimal, false),
            $report === self::CARD . 'addressbook-query' && $isAddressBook => $this->addressBookQuery($request, $root, $node, $minimal),
            $report === '{DAV:}sync-collection' && ($location->type === NodeType::Calendar || $location->type === NodeType::AddressBook)
                => $this->syncCollection($root, $node, $minimal),
            default => throw new HttpException(403, 'The requested report is not supported on this resource', '{DAV:}supported-report'),
        };
    }

    private function multiget(DOMElement $root, Node $collection, bool $minimal, bool $calendar): Response
    {
        $props = $this->requestedProps($root);
        $multiStatus = new MultiStatus();
        $expected = $calendar ? NodeType::CalendarObject : NodeType::Card;

        foreach (XmlUtil::children($root, '{DAV:}href') as $hrefElement) {
            $href = trim($hrefElement->textContent);
            $location = $this->context->router->resolveHref($href);

            if ($location === null || $location->type !== $expected) {
                $multiStatus->addStatusResponse($href, 404);
                continue;
            }
            if ($location->user !== $this->context->user) {
                $multiStatus->addStatusResponse($href, 403);
                continue;
            }
            $node = $this->context->tree->get($location);
            if ($node === null) {
                $multiStatus->addStatusResponse($href, 404);
                continue;
            }
            $this->addNode($multiStatus, $node, $props, $minimal);
        }

        return $multiStatus->toResponse();
    }

    private function calendarQuery(Request $request, DOMElement $root, Node $node, bool $minimal): Response
    {
        $filterElement = XmlUtil::child($root, self::CAL . 'filter')
            ?? throw new HttpException(403, 'calendar-query requires a filter', self::CAL . 'valid-filter');

        $timezone = $this->queryTimezone($root, $node);
        $filter = new CalendarQueryFilter($filterElement, $timezone);
        $props = $this->requestedProps($root);
        $multiStatus = new MultiStatus();

        if ($node->calendarObject !== null) {
            // Depth: 0 query against a single object.
            $candidates = [$node];
        } else {
            [$type, $start, $end] = $filter->hints();
            $calendar = $node->calendar ?? throw new HttpException(404, 'Not Found');
            $candidates = [];
            foreach ($this->context->calendars()->queryCalendarObjects($calendar->id, $type, $start, $end) as $object) {
                $location = new Location(NodeType::CalendarObject, $node->location->user, $node->location->collection, $object->uri);
                $candidates[] = new Node($location, $this->context->router->href($location), calendar: $calendar, calendarObject: $object);
            }
        }

        foreach ($candidates as $candidate) {
            try {
                $vcalendar = Parser::parse((string) $candidate->calendarObject?->data);
            } catch (ParseException) {
                continue;
            }
            if ($filter->matches($vcalendar)) {
                $this->addNode($multiStatus, $candidate, $props, $minimal);
            }
        }

        return $multiStatus->toResponse();
    }

    private function addressBookQuery(Request $request, DOMElement $root, Node $node, bool $minimal): Response
    {
        $filter = new AddressBookQueryFilter(XmlUtil::child($root, self::CARD . 'filter'));
        $props = $this->requestedProps($root);

        $limit = null;
        $limitElement = XmlUtil::child($root, self::CARD . 'limit');
        if ($limitElement !== null) {
            $nResults = XmlUtil::child($limitElement, self::CARD . 'nresults');
            if ($nResults !== null && ctype_digit(trim($nResults->textContent))) {
                $limit = (int) trim($nResults->textContent);
            }
        }

        $candidates = $node->card !== null ? [$node] : $this->context->tree->children($node);
        $multiStatus = new MultiStatus();
        $count = 0;
        $truncated = false;

        foreach ($candidates as $candidate) {
            try {
                $vcard = Parser::parse((string) $candidate->card?->data);
            } catch (ParseException) {
                continue;
            }
            if (!$filter->matches($vcard)) {
                continue;
            }
            if ($limit !== null && $count >= $limit) {
                $truncated = true;
                break;
            }
            $this->addNode($multiStatus, $candidate, $props, $minimal);
            $count++;
        }

        if ($truncated) {
            $multiStatus->addStatusResponse($node->href, 507);
        }

        return $multiStatus->toResponse();
    }

    private function syncCollection(DOMElement $root, Node $node, bool $minimal): Response
    {
        $tokenElement = XmlUtil::child($root, '{DAV:}sync-token');
        $token = $tokenElement !== null ? trim($tokenElement->textContent) : '';
        $props = $this->requestedProps($root);
        $multiStatus = new MultiStatus();

        $isCalendar = $node->calendar !== null;
        $collectionId = $isCalendar ? $node->calendar?->id : $node->addressBook?->id;
        $currentToken = (int) ($isCalendar ? $node->calendar?->syncToken : $node->addressBook?->syncToken);

        if ($token === '') {
            foreach ($this->context->tree->children($node) as $child) {
                $this->addNode($multiStatus, $child, $props, $minimal);
            }
            $multiStatus->setSyncToken(Context::syncToken($currentToken));

            return $multiStatus->toResponse();
        }

        $parsed = Context::parseSyncToken($token);
        $changes = null;
        if ($parsed !== null && $collectionId !== null) {
            $changes = $isCalendar
                ? $this->context->calendars()->getChangesSince($collectionId, $parsed)
                : $this->context->addressBooks()->getChangesSince($collectionId, $parsed);
        }
        if ($changes === null) {
            throw new HttpException(403, 'Invalid or expired sync token', '{DAV:}valid-sync-token');
        }

        foreach ($changes->changed as $uri) {
            $location = new Location(
                $isCalendar ? NodeType::CalendarObject : NodeType::Card,
                $node->location->user,
                $node->location->collection,
                $uri,
            );
            $child = $this->context->tree->get($location);
            if ($child === null) {
                $multiStatus->addStatusResponse($this->context->router->href($location), 404);
            } else {
                $this->addNode($multiStatus, $child, $props, $minimal);
            }
        }
        foreach ($changes->deleted as $uri) {
            $location = new Location(
                $isCalendar ? NodeType::CalendarObject : NodeType::Card,
                $node->location->user,
                $node->location->collection,
                $uri,
            );
            $multiStatus->addStatusResponse($this->context->router->href($location), 404);
        }

        $multiStatus->setSyncToken(Context::syncToken($changes->syncToken));

        return $multiStatus->toResponse();
    }

    /**
     * @param list<string>|null $props
     */
    private function addNode(MultiStatus $multiStatus, Node $node, ?array $props, bool $minimal): void
    {
        [$found, $notFound] = $this->context->properties->resolve($node, $props);
        $multiStatus->addPropResponse($node->href, [
            200 => $found,
            404 => $minimal ? [] : array_fill_keys($notFound, null),
        ]);
    }

    /**
     * @return list<string>|null null means allprop
     */
    private function requestedProps(DOMElement $root): ?array
    {
        $prop = XmlUtil::child($root, '{DAV:}prop');
        if ($prop !== null) {
            return XmlUtil::propNames($prop);
        }
        if (XmlUtil::child($root, '{DAV:}allprop') !== null) {
            return null;
        }

        return ['{DAV:}getetag'];
    }

    private function queryTimezone(DOMElement $root, Node $node): ?DateTimeZone
    {
        $tzElement = XmlUtil::child($root, self::CAL . 'timezone');
        $data = $tzElement?->textContent ?? $node->calendar?->timezone;
        if ($data === null || trim($data) === '') {
            return null;
        }
        if (preg_match('/^TZID[;:][^\r\n]*?([A-Za-z_]+\/[A-Za-z0-9_+\-\/]+)\s*$/m', $data, $m)) {
            try {
                return new DateTimeZone($m[1]);
            } catch (\Exception) {
            }
        }

        return null;
    }
}
