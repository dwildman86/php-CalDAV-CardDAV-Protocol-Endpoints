<?php

declare(strict_types=1);

namespace GhostParse\Dav\Xml;

use Closure;
use DOMElement;

/**
 * Factories for complex (element-valued) property values.
 */
final class Value
{
    /**
     * One or more DAV:href elements.
     *
     * @param string|list<string> $hrefs
     */
    public static function href(string|array $hrefs): Closure
    {
        $hrefs = (array) $hrefs;

        return static function (XmlWriter $w, DOMElement $parent) use ($hrefs): void {
            foreach ($hrefs as $href) {
                $parent->appendChild($w->element('{DAV:}href', $href));
            }
        };
    }

    /**
     * A list of empty elements (e.g. for DAV:resourcetype).
     *
     * @param list<string> $names
     */
    public static function elements(array $names): Closure
    {
        return static function (XmlWriter $w, DOMElement $parent) use ($names): void {
            foreach ($names as $name) {
                $parent->appendChild($w->element($name));
            }
        };
    }

    /**
     * @param list<string> $reports
     */
    public static function supportedReports(array $reports): Closure
    {
        return static function (XmlWriter $w, DOMElement $parent) use ($reports): void {
            foreach ($reports as $report) {
                $supported = $w->element('{DAV:}supported-report');
                $wrapper = $w->element('{DAV:}report');
                $wrapper->appendChild($w->element($report));
                $supported->appendChild($wrapper);
                $parent->appendChild($supported);
            }
        };
    }

    /**
     * @param list<string> $privileges
     */
    public static function privileges(array $privileges): Closure
    {
        return static function (XmlWriter $w, DOMElement $parent) use ($privileges): void {
            foreach ($privileges as $privilege) {
                $element = $w->element('{DAV:}privilege');
                $element->appendChild($w->element($privilege));
                $parent->appendChild($element);
            }
        };
    }

    /**
     * CALDAV:supported-calendar-component-set contents.
     *
     * @param list<string> $components
     */
    public static function components(array $components): Closure
    {
        return static function (XmlWriter $w, DOMElement $parent) use ($components): void {
            foreach ($components as $component) {
                $element = $w->element('{' . Ns::CALDAV . '}comp');
                $element->setAttribute('name', $component);
                $parent->appendChild($element);
            }
        };
    }

    /**
     * A list of data-type elements with content-type / version attributes.
     *
     * @param list<array{0: string, 1: string}> $types [content-type, version]
     */
    public static function dataTypes(string $elementName, array $types): Closure
    {
        return static function (XmlWriter $w, DOMElement $parent) use ($elementName, $types): void {
            foreach ($types as [$contentType, $version]) {
                $element = $w->element($elementName);
                $element->setAttribute('content-type', $contentType);
                $element->setAttribute('version', $version);
                $parent->appendChild($element);
            }
        };
    }
}
