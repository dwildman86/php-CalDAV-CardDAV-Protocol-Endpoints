<?php

declare(strict_types=1);

namespace GhostParse\Dav\Handler;

use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\Http\Request;
use GhostParse\Dav\Http\Response;
use GhostParse\Dav\Routing\Location;
use GhostParse\Dav\Routing\NodeType;
use GhostParse\Dav\Xml\Ns;
use GhostParse\Dav\Xml\XmlUtil;

/**
 * MKCALENDAR (RFC 4791 §5.3.1), extended MKCOL (RFC 5689) and collection DELETE.
 */
final class CollectionHandler
{
    public function __construct(private Context $context)
    {
    }

    public function mkcalendar(Request $request, Location $location): Response
    {
        if ($location->type !== NodeType::Calendar) {
            throw new HttpException(403, 'Calendars can only be created inside a calendar home', '{' . Ns::CALDAV . '}calendar-collection-location-ok');
        }
        $this->assertDoesNotExist($location);

        $prop = null;
        if (trim($request->getBody()) !== '') {
            $root = XmlUtil::parse($request->getBody())->documentElement;
            if ($root === null || XmlUtil::clark($root) !== '{' . Ns::CALDAV . '}mkcalendar') {
                throw new HttpException(400, 'Expected a CALDAV:mkcalendar request body');
            }
            $prop = $this->setProp($root);
        }

        $this->createCalendar($location, $prop);

        return new Response(201, ['Content-Length' => '0']);
    }

    public function mkcol(Request $request, Location $location): Response
    {
        if ($location->type !== NodeType::Calendar && $location->type !== NodeType::AddressBook) {
            throw new HttpException(403, 'Collections can only be created inside a calendar or address book home');
        }
        $this->assertDoesNotExist($location);

        $prop = null;
        if (trim($request->getBody()) !== '') {
            $root = XmlUtil::parse($request->getBody())->documentElement;
            if ($root === null || XmlUtil::clark($root) !== '{DAV:}mkcol') {
                throw new HttpException(415, 'Expected a DAV:mkcol request body');
            }
            $prop = $this->setProp($root);

            $resourceType = $prop !== null ? XmlUtil::child($prop, '{DAV:}resourcetype') : null;
            if ($resourceType !== null) {
                $types = array_map([XmlUtil::class, 'clark'], XmlUtil::children($resourceType));
                $expected = $location->type === NodeType::Calendar
                    ? '{' . Ns::CALDAV . '}calendar'
                    : '{' . Ns::CARDDAV . '}addressbook';
                if (!in_array($expected, $types, true)) {
                    throw new HttpException(403, 'Unsupported resource type for this location', '{DAV:}valid-resourcetype');
                }
            }
        }

        if ($location->type === NodeType::Calendar) {
            $this->createCalendar($location, $prop);
        } else {
            $this->context->addressBooks()->createAddressBook(
                (string) $location->user,
                (string) $location->collection,
                WritableProperties::collectInitial($prop, WritableProperties::addressBookMap(), false),
            );
        }

        return new Response(201, ['Content-Length' => '0']);
    }

    public function delete(Location $location): Response
    {
        $node = $this->context->tree->get($location) ?? throw new HttpException(404, 'Not Found');

        if ($node->calendar !== null && $location->type === NodeType::Calendar) {
            $this->context->calendars()->deleteCalendar($node->calendar->id);
        } elseif ($node->addressBook !== null && $location->type === NodeType::AddressBook) {
            $this->context->addressBooks()->deleteAddressBook($node->addressBook->id);
        } else {
            throw new HttpException(403, 'This collection cannot be deleted');
        }

        return new Response(204);
    }

    private function createCalendar(Location $location, ?\DOMElement $prop): void
    {
        $this->context->calendars()->createCalendar(
            (string) $location->user,
            (string) $location->collection,
            WritableProperties::collectInitial($prop, WritableProperties::calendarMap(), true),
        );
    }

    private function setProp(\DOMElement $root): ?\DOMElement
    {
        $set = XmlUtil::child($root, '{DAV:}set');

        return $set !== null ? XmlUtil::child($set, '{DAV:}prop') : null;
    }

    private function assertDoesNotExist(Location $location): void
    {
        if ($this->context->tree->get($location) !== null) {
            throw new HttpException(405, 'A resource already exists at this location', '{DAV:}resource-must-be-null');
        }
    }
}
