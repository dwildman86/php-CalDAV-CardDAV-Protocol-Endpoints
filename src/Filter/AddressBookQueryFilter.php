<?php

declare(strict_types=1);

namespace GhostParse\Dav\Filter;

use DOMElement;
use GhostParse\Dav\VObject\Component;
use GhostParse\Dav\VObject\Property;
use GhostParse\Dav\Xml\Ns;
use GhostParse\Dav\Xml\XmlUtil;

/**
 * Evaluates a CARDDAV:filter element (RFC 6352 §10.5) against a VCARD.
 */
final class AddressBookQueryFilter
{
    private const C = '{' . Ns::CARDDAV . '}';
    private const DEFAULT_COLLATION = 'i;unicode-casemap';

    public function __construct(private ?DOMElement $filter)
    {
    }

    public function matches(Component $vcard): bool
    {
        if ($this->filter === null) {
            return true;
        }
        $propFilters = XmlUtil::children($this->filter, self::C . 'prop-filter');
        if ($propFilters === []) {
            return true;
        }

        $allOf = strtolower($this->filter->getAttribute('test')) === 'allof';
        foreach ($propFilters as $propFilter) {
            $result = $this->propFilterMatches($vcard, $propFilter);
            if ($allOf && !$result) {
                return false;
            }
            if (!$allOf && $result) {
                return true;
            }
        }

        return $allOf;
    }

    private function propFilterMatches(Component $vcard, DOMElement $filter): bool
    {
        $properties = $vcard->getProperties(strtoupper($filter->getAttribute('name')));
        if (XmlUtil::child($filter, self::C . 'is-not-defined') !== null) {
            return $properties === [];
        }
        if ($properties === []) {
            return false;
        }

        $conditions = array_values(array_filter(
            XmlUtil::children($filter),
            static fn (DOMElement $e): bool => in_array(XmlUtil::clark($e), [self::C . 'text-match', self::C . 'param-filter'], true),
        ));
        if ($conditions === []) {
            return true;
        }

        $allOf = strtolower($filter->getAttribute('test')) === 'allof';
        foreach ($properties as $property) {
            if ($this->propertyMatches($property, $conditions, $allOf)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<DOMElement> $conditions
     */
    private function propertyMatches(Property $property, array $conditions, bool $allOf): bool
    {
        foreach ($conditions as $condition) {
            $result = XmlUtil::clark($condition) === self::C . 'text-match'
                ? TextMatcher::matches($property->getTextValue(), $condition, self::DEFAULT_COLLATION)
                : $this->paramFilterMatches($property, $condition);

            if ($allOf && !$result) {
                return false;
            }
            if (!$allOf && $result) {
                return true;
            }
        }

        return $allOf;
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
            // TYPE may be comma separated inside a single value.
            foreach (explode(',', $value) as $part) {
                if (TextMatcher::matches($part, $textMatch, self::DEFAULT_COLLATION)) {
                    return true;
                }
            }
        }

        return false;
    }
}
