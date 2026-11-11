<?php

declare(strict_types=1);

namespace GhostParse\Dav\Handler;

use GhostParse\Dav\Routing\Location;
use GhostParse\Dav\Routing\Node;
use GhostParse\Dav\Routing\NodeType;
use GhostParse\Dav\Xml\Ns;
use GhostParse\Dav\Xml\Value;

/**
 * Computes WebDAV / CalDAV / CardDAV properties for nodes.
 */
final class PropertyResolver
{
    private const D = '{DAV:}';
    private const CAL = '{' . Ns::CALDAV . '}';
    private const CARD = '{' . Ns::CARDDAV . '}';
    private const CS = '{' . Ns::CALENDARSERVER . '}';
    private const ICAL = '{' . Ns::APPLE_ICAL . '}';

    private static ?object $missing = null;

    public function __construct(private Context $context)
    {
    }

    /**
     * Resolve the requested properties (or all properties when $names is null).
     *
     * @param list<string>|null $names
     * @return array{0: array<string, mixed>, 1: list<string>} [found, notFound]
     */
    public function resolve(Node $node, ?array $names): array
    {
        $names ??= $this->allPropNames($node);
        $found = [];
        $notFound = [];
        foreach ($names as $name) {
            $value = $this->value($node, $name);
            if ($value === self::missing()) {
                $notFound[] = $name;
            } else {
                $found[$name] = $value;
            }
        }

        return [$found, $notFound];
    }

    /**
     * Names returned for DAV:allprop and DAV:propname.
     *
     * @return list<string>
     */
    public function allPropNames(Node $node): array
    {
        $names = [
            self::D . 'resourcetype',
            self::D . 'current-user-principal',
            self::D . 'current-user-privilege-set',
            self::D . 'supported-report-set',
        ];

        switch ($node->type()) {
            case NodeType::Principal:
                array_push(
                    $names,
                    self::D . 'displayname',
                    self::D . 'principal-URL',
                    self::D . 'alternate-URI-set',
                    self::D . 'group-membership',
                );
                if ($this->context->calendars !== null) {
                    array_push($names, self::CAL . 'calendar-home-set', self::CAL . 'calendar-user-address-set', self::CAL . 'calendar-user-type');
                }
                if ($this->context->addressBooks !== null) {
                    $names[] = self::CARD . 'addressbook-home-set';
                }
                break;

            case NodeType::CalendarHome:
            case NodeType::AddressBookHome:
                $names[] = self::D . 'owner';
                break;

            case NodeType::Calendar:
                array_push(
                    $names,
                    self::D . 'displayname',
                    self::D . 'owner',
                    self::D . 'sync-token',
                    self::CS . 'getctag',
                    self::CAL . 'calendar-description',
                    self::CAL . 'supported-calendar-component-set',
                    self::CAL . 'supported-calendar-data',
                    self::CAL . 'max-resource-size',
                    self::ICAL . 'calendar-color',
                    self::ICAL . 'calendar-order',
                );
                if ($node->calendar?->timezone !== null) {
                    $names[] = self::CAL . 'calendar-timezone';
                }
                break;

            case NodeType::AddressBook:
                array_push(
                    $names,
                    self::D . 'displayname',
                    self::D . 'owner',
                    self::D . 'sync-token',
                    self::CS . 'getctag',
                    self::CARD . 'addressbook-description',
                    self::CARD . 'supported-address-data',
                    self::CARD . 'max-resource-size',
                );
                break;

            case NodeType::CalendarObject:
            case NodeType::Card:
                array_push(
                    $names,
                    self::D . 'getetag',
                    self::D . 'getcontenttype',
                    self::D . 'getcontentlength',
                    self::D . 'getlastmodified',
                    self::D . 'owner',
                );
                break;

            default:
                break;
        }

        return $names;
    }

    public static function missing(): object
    {
        return self::$missing ??= new \stdClass();
    }

    public function value(Node $node, string $name): mixed
    {
        $type = $node->type();
        $router = $this->context->router;
        $missing = self::missing();

        switch ($name) {
            // ---- Generic WebDAV / ACL -------------------------------------------------
            case self::D . 'resourcetype':
                return Value::elements(match ($type) {
                    NodeType::Principal => [self::D . 'collection', self::D . 'principal'],
                    NodeType::Calendar => [self::D . 'collection', self::CAL . 'calendar'],
                    NodeType::AddressBook => [self::D . 'collection', self::CARD . 'addressbook'],
                    NodeType::CalendarObject, NodeType::Card => [],
                    default => [self::D . 'collection'],
                });

            case self::D . 'displayname':
                return match ($type) {
                    NodeType::Principal => $node->principal?->displayName ?? $node->principal?->username,
                    NodeType::Calendar => $node->calendar?->displayName ?? $node->calendar?->uri,
                    NodeType::AddressBook => $node->addressBook?->displayName ?? $node->addressBook?->uri,
                    default => $missing,
                };

            case self::D . 'current-user-principal':
                return Value::href($router->principalHref($this->context->user));

            case self::D . 'principal-collection-set':
                return Value::href($router->href(new Location(NodeType::PrincipalCollection)));

            case self::D . 'owner':
                if ($type->isUserScoped()) {
                    return Value::href($router->principalHref((string) $node->location->user));
                }
                if ($type === NodeType::Principal) {
                    return Value::href($node->href);
                }

                return $missing;

            case self::D . 'current-user-privilege-set':
                $owned = ($type->isUserScoped() || $type === NodeType::Principal)
                    && $node->location->user === $this->context->user;

                return Value::privileges($owned
                    ? [
                        self::D . 'read', self::D . 'write', self::D . 'write-properties', self::D . 'write-content',
                        self::D . 'bind', self::D . 'unbind', self::D . 'read-current-user-privilege-set',
                    ]
                    : [self::D . 'read', self::D . 'read-current-user-privilege-set']);

            case self::D . 'supported-report-set':
                return Value::supportedReports(match ($type) {
                    NodeType::Calendar, NodeType::CalendarObject => [self::CAL . 'calendar-query', self::CAL . 'calendar-multiget', self::D . 'sync-collection'],
                    NodeType::AddressBook, NodeType::Card => [self::CARD . 'addressbook-query', self::CARD . 'addressbook-multiget', self::D . 'sync-collection'],
                    default => [],
                });

            case self::D . 'sync-token':
                return match ($type) {
                    NodeType::Calendar => Context::syncToken((int) $node->calendar?->syncToken),
                    NodeType::AddressBook => Context::syncToken((int) $node->addressBook?->syncToken),
                    default => $missing,
                };

            case self::CS . 'getctag':
                return match ($type) {
                    NodeType::Calendar => (string) $node->calendar?->syncToken,
                    NodeType::AddressBook => (string) $node->addressBook?->syncToken,
                    default => $missing,
                };

            case self::D . 'getetag':
                return $node->calendarObject?->etag ?? $node->card?->etag ?? $missing;

            case self::D . 'getcontentlength':
                return $node->calendarObject?->size ?? $node->card?->size ?? $missing;

            case self::D . 'getlastmodified':
                $modified = $node->calendarObject?->lastModified ?? $node->card?->lastModified;

                return $modified === null ? $missing : gmdate('D, d M Y H:i:s', $modified) . ' GMT';

            case self::D . 'getcontenttype':
                if ($node->calendarObject !== null) {
                    return self::calendarContentType($node->calendarObject->componentType);
                }

                return $node->card !== null ? 'text/vcard; charset=utf-8' : $missing;

            // ---- Principal properties --------------------------------------------------
            case self::D . 'principal-URL':
                return $type === NodeType::Principal ? Value::href($node->href) : $missing;

            case self::D . 'alternate-URI-set':
                if ($type !== NodeType::Principal) {
                    return $missing;
                }
                $email = $node->principal?->email;

                return Value::href($email !== null && $email !== '' ? ['mailto:' . $email] : []);

            case self::D . 'group-membership':
            case self::D . 'group-member-set':
                return $type === NodeType::Principal ? Value::href([]) : $missing;

            case self::CAL . 'calendar-home-set':
                if ($type !== NodeType::Principal || $this->context->calendars === null) {
                    return $missing;
                }

                return Value::href($router->href(new Location(NodeType::CalendarHome, $node->location->user)));

            case self::CAL . 'calendar-user-address-set':
                if ($type !== NodeType::Principal || $this->context->calendars === null) {
                    return $missing;
                }
                $hrefs = [];
                if (($node->principal?->email ?? '') !== '') {
                    $hrefs[] = 'mailto:' . $node->principal?->email;
                }
                $hrefs[] = $node->href;

                return Value::href($hrefs);

            case self::CAL . 'calendar-user-type':
                return $type === NodeType::Principal && $this->context->calendars !== null ? 'INDIVIDUAL' : $missing;

            case self::CARD . 'addressbook-home-set':
                if ($type !== NodeType::Principal || $this->context->addressBooks === null) {
                    return $missing;
                }

                return Value::href($router->href(new Location(NodeType::AddressBookHome, $node->location->user)));

            // ---- Calendar collection ---------------------------------------------------
            case self::CAL . 'calendar-description':
                return $node->calendar !== null && $type === NodeType::Calendar
                    ? ($node->calendar->description ?? '') : $missing;

            case self::CAL . 'calendar-timezone':
                return $type === NodeType::Calendar && $node->calendar?->timezone !== null
                    ? $node->calendar->timezone : $missing;

            case self::CAL . 'supported-calendar-component-set':
                return $type === NodeType::Calendar && $node->calendar !== null
                    ? Value::components($node->calendar->components) : $missing;

            case self::CAL . 'supported-calendar-data':
                return $type === NodeType::Calendar
                    ? Value::dataTypes(self::CAL . 'calendar-data', [['text/calendar', '2.0']]) : $missing;

            case self::CAL . 'max-resource-size':
                return $type === NodeType::Calendar ? $this->context->maxResourceSize : $missing;

            case self::ICAL . 'calendar-color':
                return $type === NodeType::Calendar && $node->calendar?->color !== null
                    ? $node->calendar->color : $missing;

            case self::ICAL . 'calendar-order':
                return $type === NodeType::Calendar && $node->calendar?->order !== null
                    ? $node->calendar->order : $missing;

            case self::CAL . 'calendar-data':
                return $node->calendarObject?->data ?? $missing;

            // ---- Address book collection -----------------------------------------------
            case self::CARD . 'addressbook-description':
                return $node->addressBook !== null && $type === NodeType::AddressBook
                    ? ($node->addressBook->description ?? '') : $missing;

            case self::CARD . 'supported-address-data':
                return $type === NodeType::AddressBook
                    ? Value::dataTypes(self::CARD . 'address-data-type', [['text/vcard', '3.0'], ['text/vcard', '4.0']])
                    : $missing;

            case self::CARD . 'max-resource-size':
                return $type === NodeType::AddressBook ? $this->context->maxResourceSize : $missing;

            case self::CARD . 'address-data':
                return $node->card?->data ?? $missing;
        }

        return $missing;
    }

    public static function calendarContentType(?string $componentType): string
    {
        $type = 'text/calendar; charset=utf-8';
        if ($componentType !== null && $componentType !== '') {
            $type .= '; component=' . strtolower($componentType);
        }

        return $type;
    }
}
