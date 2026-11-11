<?php

declare(strict_types=1);

namespace GhostParse\Dav\Handler;

use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\Http\Request;
use GhostParse\Dav\Http\Response;
use GhostParse\Dav\Routing\Location;
use GhostParse\Dav\Routing\NodeType;
use GhostParse\Dav\Xml\MultiStatus;
use GhostParse\Dav\Xml\XmlUtil;

/**
 * PROPPATCH (RFC 4918 §9.2). All-or-nothing semantics.
 */
final class ProppatchHandler
{
    public function __construct(private Context $context)
    {
    }

    public function handle(Request $request, Location $location): Response
    {
        $node = $this->context->tree->get($location) ?? throw new HttpException(404, 'Not Found');

        $root = XmlUtil::parse($request->getBody())->documentElement;
        if ($root === null || XmlUtil::clark($root) !== '{DAV:}propertyupdate') {
            throw new HttpException(400, 'Expected a DAV:propertyupdate request body');
        }

        $map = match ($location->type) {
            NodeType::Calendar => WritableProperties::calendarMap(),
            NodeType::AddressBook => WritableProperties::addressBookMap(),
            default => [],
        };

        /** @var array<string, mixed> $updates backend key => value */
        $updates = [];
        $accepted = [];
        $rejected = [];

        foreach (XmlUtil::children($root) as $instruction) {
            $kind = XmlUtil::clark($instruction);
            if ($kind !== '{DAV:}set' && $kind !== '{DAV:}remove') {
                continue;
            }
            $prop = XmlUtil::child($instruction, '{DAV:}prop');
            if ($prop === null) {
                continue;
            }
            foreach (XmlUtil::children($prop) as $element) {
                $clark = XmlUtil::clark($element);
                if (isset($map[$clark])) {
                    $key = $map[$clark];
                    $updates[$key] = $kind === '{DAV:}set' ? WritableProperties::readValue($key, $element) : null;
                    $accepted[$clark] = null;
                } elseif ($map !== [] && WritableProperties::isIgnored($clark)) {
                    $accepted[$clark] = null;
                } else {
                    $rejected[$clark] = null;
                }
            }
        }

        $multiStatus = new MultiStatus();
        if ($rejected !== []) {
            $multiStatus->addPropResponse($node->href, [403 => $rejected, 424 => $accepted]);

            return $multiStatus->toResponse();
        }

        if ($updates !== []) {
            if ($node->calendar !== null) {
                $this->context->calendars()->updateCalendar($node->calendar->id, $updates);
            } elseif ($node->addressBook !== null) {
                $this->context->addressBooks()->updateAddressBook($node->addressBook->id, $updates);
            }
        }

        $multiStatus->addPropResponse($node->href, [200 => $accepted]);

        return $multiStatus->toResponse();
    }
}
