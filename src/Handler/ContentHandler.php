<?php

declare(strict_types=1);

namespace GhostParse\Dav\Handler;

use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\Http\Request;
use GhostParse\Dav\Http\Response;
use GhostParse\Dav\Routing\Location;
use GhostParse\Dav\Routing\NodeType;
use GhostParse\Dav\Server;
use GhostParse\Dav\VObject\Component;
use GhostParse\Dav\VObject\ObjectValidator;
use GhostParse\Dav\VObject\ParseException;
use GhostParse\Dav\VObject\Parser;
use GhostParse\Dav\VObject\Property;
use GhostParse\Dav\Xml\Ns;

/**
 * GET / HEAD / PUT / DELETE for calendar objects and vCards, plus
 * collection export via GET.
 */
final class ContentHandler
{
    public function __construct(private Context $context)
    {
    }

    public function get(Request $request, Location $location): Response
    {
        if ($location->type === NodeType::Root) {
            return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'GhostParse CalDAV/CardDAV server ' . Server::VERSION . "\n");
        }

        $node = $this->context->tree->get($location) ?? throw new HttpException(404, 'Not Found');

        if ($node->calendarObject !== null) {
            $object = $node->calendarObject;

            return $this->entityResponse(
                $request,
                $object->data,
                PropertyResolver::calendarContentType($object->componentType),
                $object->etag,
                $object->lastModified,
            );
        }

        if ($node->card !== null) {
            $card = $node->card;

            return $this->entityResponse($request, $card->data, 'text/vcard; charset=utf-8', $card->etag, $card->lastModified);
        }

        if ($node->calendar !== null) {
            $objects = $this->context->calendars()->getCalendarObjects($node->calendar->id);
            $body = $this->exportCalendar(array_map(static fn ($o): string => $o->data, $objects), $node->calendar->displayName ?? $node->calendar->uri);
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $node->calendar->uri) . '.ics';

            return new Response(200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ], $body);
        }

        if ($node->addressBook !== null) {
            $cards = $this->context->addressBooks()->getCards($node->addressBook->id);
            $body = '';
            foreach ($cards as $card) {
                $body .= rtrim($card->data, "\r\n") . "\r\n";
            }
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $node->addressBook->uri) . '.vcf';

            return new Response(200, [
                'Content-Type' => 'text/vcard; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ], $body);
        }

        throw new HttpException(405, 'GET is not supported on this resource');
    }

    public function put(Request $request, Location $location): Response
    {
        $data = $request->getBody();
        if (strlen($data) > $this->context->maxResourceSize) {
            $condition = $location->type === NodeType::Card
                ? '{' . Ns::CARDDAV . '}max-resource-size'
                : '{' . Ns::CALDAV . '}max-resource-size';
            throw new HttpException(403, 'Resource exceeds the maximum allowed size', $condition);
        }

        return match ($location->type) {
            NodeType::CalendarObject => $this->putCalendarObject($request, $location, $data),
            NodeType::Card => $this->putCard($request, $location, $data),
            default => throw new HttpException(405, 'PUT is not supported on this resource'),
        };
    }

    public function delete(Request $request, Location $location): Response
    {
        $node = $this->context->tree->get($location) ?? throw new HttpException(404, 'Not Found');

        if ($node->calendarObject !== null && $node->calendar !== null) {
            self::checkPreconditions($request, $node->calendarObject->etag);
            $this->context->calendars()->deleteCalendarObject($node->calendar->id, $node->calendarObject->uri);
        } elseif ($node->card !== null && $node->addressBook !== null) {
            self::checkPreconditions($request, $node->card->etag);
            $this->context->addressBooks()->deleteCard($node->addressBook->id, $node->card->uri);
        } else {
            throw new HttpException(405, 'DELETE is not supported on this resource');
        }

        return new Response(204);
    }

    private function putCalendarObject(Request $request, Location $location, string $data): Response
    {
        $backend = $this->context->calendars();
        $calendar = $backend->getCalendar((string) $location->user, (string) $location->collection)
            ?? throw new HttpException(409, 'The parent calendar does not exist');

        $existing = $backend->getCalendarObject($calendar->id, (string) $location->object);
        self::checkPreconditions($request, $existing?->etag);

        try {
            $metadata = ObjectValidator::calendarObject($data);
        } catch (ParseException $e) {
            throw new HttpException(403, $e->getMessage(), '{' . Ns::CALDAV . '}valid-calendar-data', [], $e);
        }

        if (!in_array((string) $metadata->componentType, $calendar->components, true)) {
            throw new HttpException(
                403,
                sprintf('This calendar does not accept %s components', $metadata->componentType),
                '{' . Ns::CALDAV . '}supported-calendar-component',
            );
        }

        $other = $backend->findCalendarObjectUriByUid($calendar->id, $metadata->uid);
        if ($other !== null && $other !== $location->object) {
            throw new HttpException(403, 'An object with the same UID already exists in this calendar', '{' . Ns::CALDAV . '}no-uid-conflict');
        }

        $etag = $existing === null
            ? $backend->createCalendarObject($calendar->id, (string) $location->object, $data, $metadata)
            : $backend->updateCalendarObject($calendar->id, (string) $location->object, $data, $metadata);

        return new Response($existing === null ? 201 : 204, ['ETag' => $etag]);
    }

    private function putCard(Request $request, Location $location, string $data): Response
    {
        $backend = $this->context->addressBooks();
        $book = $backend->getAddressBook((string) $location->user, (string) $location->collection)
            ?? throw new HttpException(409, 'The parent address book does not exist');

        $existing = $backend->getCard($book->id, (string) $location->object);
        self::checkPreconditions($request, $existing?->etag);

        try {
            $metadata = ObjectValidator::vcard($data);
        } catch (ParseException $e) {
            throw new HttpException(403, $e->getMessage(), '{' . Ns::CARDDAV . '}valid-address-data', [], $e);
        }

        $other = $backend->findCardUriByUid($book->id, $metadata->uid);
        if ($other !== null && $other !== $location->object) {
            throw new HttpException(403, 'A card with the same UID already exists in this address book', '{' . Ns::CARDDAV . '}no-uid-conflict');
        }

        $etag = $existing === null
            ? $backend->createCard($book->id, (string) $location->object, $data, $metadata)
            : $backend->updateCard($book->id, (string) $location->object, $data, $metadata);

        return new Response($existing === null ? 201 : 204, ['ETag' => $etag]);
    }

    private function entityResponse(Request $request, string $body, string $contentType, string $etag, int $lastModified): Response
    {
        $headers = [
            'Content-Type' => $contentType,
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ];

        $ifNoneMatch = $request->getHeader('If-None-Match');
        if ($ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $etag)) {
            return new Response(304, $headers);
        }

        return new Response(200, $headers + ['Content-Length' => (string) strlen($body)], $body);
    }

    /**
     * Evaluate If-Match / If-None-Match for state-changing requests.
     */
    public static function checkPreconditions(Request $request, ?string $currentEtag): void
    {
        $ifMatch = $request->getHeader('If-Match');
        if ($ifMatch !== null) {
            if ($currentEtag === null || !self::etagMatches($ifMatch, $currentEtag)) {
                throw new HttpException(412, 'If-Match precondition failed');
            }
        }

        $ifNoneMatch = $request->getHeader('If-None-Match');
        if ($ifNoneMatch !== null && $currentEtag !== null && self::etagMatches($ifNoneMatch, $currentEtag)) {
            throw new HttpException(412, 'If-None-Match precondition failed');
        }
    }

    private static function etagMatches(string $header, string $etag): bool
    {
        $header = trim($header);
        if ($header === '*') {
            return true;
        }
        $normalize = static fn (string $t): string => trim(preg_replace('/^W\//', '', trim($t)) ?? '');
        foreach (explode(',', $header) as $candidate) {
            if ($normalize($candidate) === $normalize($etag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge calendar objects into a single VCALENDAR for download.
     *
     * @param list<string> $objects
     */
    private function exportCalendar(array $objects, string $name): string
    {
        $timezones = [];
        $components = [];
        foreach ($objects as $data) {
            try {
                $root = Parser::parse($data);
            } catch (ParseException) {
                continue;
            }
            foreach ($root->children as $child) {
                if ($child->name === 'VTIMEZONE') {
                    $tzid = (string) $child->getProperty('TZID')?->value;
                    $timezones[$tzid] ??= $child;
                } else {
                    $components[] = $child;
                }
            }
        }

        $calendar = new Component('VCALENDAR');
        $calendar->properties[] = new Property('VERSION', '2.0');
        $calendar->properties[] = new Property('PRODID', '-//GhostParse//CalDAV Server ' . Server::VERSION . '//EN');
        $calendar->properties[] = new Property('X-WR-CALNAME', addcslashes($name, ',;\\'));
        $calendar->children = array_merge(array_values($timezones), $components);

        return $calendar->serialize();
    }
}
