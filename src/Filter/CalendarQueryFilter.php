<?php

declare(strict_types=1);

namespace GhostParse\Dav\Filter;

use DateTimeZone;
use DOMElement;
use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\VObject\Component;
use GhostParse\Dav\VObject\DateUtil;
use GhostParse\Dav\VObject\Property;
use GhostParse\Dav\VObject\TimeRange;
use GhostParse\Dav\Xml\Ns;
use GhostParse\Dav\Xml\XmlUtil;

/**
 * Evaluates a CALDAV:filter element (RFC 4791 §9.7) against a VCALENDAR.
 */
final class CalendarQueryFilter
{
    private const C = '{' . Ns::CALDAV . '}';

    public function __construct(
        private DOMElement $filter,
        private ?DateTimeZone $timezone = null,
    ) {
    }

    public function matches(Component $vcalendar): bool
    {
        $compFilter = XmlUtil::child($this->filter, self::C . 'comp-filter');
        if ($compFilter === null) {
            return true;
        }

        $name = strtoupper($compFilter->getAttribute('name'));
        $candidates = $vcalendar->name === $name ? [$vcalendar] : [];

        return $this->evaluateCompFilter($candidates, $compFilter);
    }

    /**
     * Extract hints for backend pre-filtering: the component type below
     * VCALENDAR and its time-range, if any.
     *
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    public function hints(): array
    {
        $root = XmlUtil::child($this->filter, self::C . 'comp-filter');
        if ($root === null) {
            return [null, null, null];
        }
        $comps = XmlUtil::children($root, self::C . 'comp-filter');
        if (count($comps) !== 1 || XmlUtil::child($comps[0], self::C . 'is-not-defined') !== null) {
            return [null, null, null];
        }
        $type = strtoupper($comps[0]->getAttribute('name'));
        $timeRange = XmlUtil::child($comps[0], self::C . 'time-range');
        if ($timeRange === null) {
            return [$type, null, null];
        }
        [$start, $end] = self::parseTimeRange($timeRange);

        return [$type, $start, $end];
    }

    /**
     * @param list<Component> $candidates
     */
    private function evaluateCompFilter(array $candidates, DOMElement $filter): bool
    {
        if (XmlUtil::child($filter, self::C . 'is-not-defined') !== null) {
            return $candidates === [];
        }
        foreach ($candidates as $component) {
            if ($this->componentMatches($component, $filter)) {
                return true;
            }
        }

        return false;
    }

    private function componentMatches(Component $component, DOMElement $filter): bool
    {
        foreach (XmlUtil::children($filter) as $child) {
            $name = XmlUtil::clark($child);
            if ($name === self::C . 'time-range') {
                [$start, $end] = self::parseTimeRange($child);
                if (!TimeRange::overlaps($component, $start, $end, $this->timezone)) {
                    return false;
                }
            } elseif ($name === self::C . 'comp-filter') {
                $sub = $component->getComponents(strtoupper($child->getAttribute('name')));
                if (!$this->evaluateCompFilter($sub, $child)) {
                    return false;
                }
            } elseif ($name === self::C . 'prop-filter') {
                if (!$this->propFilterMatches($component, $child)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function propFilterMatches(Component $component, DOMElement $filter): bool
    {
        $properties = $component->getProperties(strtoupper($filter->getAttribute('name')));
        if (XmlUtil::child($filter, self::C . 'is-not-defined') !== null) {
            return $properties === [];
        }

        foreach ($properties as $property) {
            if ($this->propertyMatches($property, $filter)) {
                return true;
            }
        }

        return false;
    }

    private function propertyMatches(Property $property, DOMElement $filter): bool
    {
        foreach (XmlUtil::children($filter) as $child) {
            $name = XmlUtil::clark($child);
            if ($name === self::C . 'time-range') {
                [$start, $end] = self::parseTimeRange($child);
                if (!TimeRange::propertyInRange($property, $start, $end, $this->timezone)) {
                    return false;
                }
            } elseif ($name === self::C . 'text-match') {
                if (!TextMatcher::matches($property->getTextValue(), $child, 'i;ascii-casemap')) {
                    return false;
                }
            } elseif ($name === self::C . 'param-filter') {
                if (!$this->paramFilterMatches($property, $child)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function paramFilterMatches(Property $property, DOMElement $filter): bool
    {
        $values = $property->getParameter($filter->getAttribute('name'));
        if (XmlUtil::child($filter, self::C . 'is-not-defined') !== null) {
            return $values === null;
        }
        if ($values === null) {
            return false;
        }
        $textMatch = XmlUtil::child($filter, self::C . 'text-match');
        if ($textMatch === null) {
            return true;
        }
        foreach ($values as $value) {
            if (TextMatcher::matches($value, $textMatch, 'i;ascii-casemap')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    public static function parseTimeRange(DOMElement $timeRange): array
    {
        $start = $timeRange->hasAttribute('start') ? DateUtil::parseUtc($timeRange->getAttribute('start')) : null;
        $end = $timeRange->hasAttribute('end') ? DateUtil::parseUtc($timeRange->getAttribute('end')) : null;

        if (($timeRange->hasAttribute('start') && $start === null) || ($timeRange->hasAttribute('end') && $end === null)) {
            throw new HttpException(400, 'Invalid time-range value', '{' . Ns::CALDAV . '}valid-filter');
        }
        if ($start === null && $end === null) {
            throw new HttpException(400, 'time-range requires a start or end attribute', '{' . Ns::CALDAV . '}valid-filter');
        }

        return [$start, $end];
    }
}
