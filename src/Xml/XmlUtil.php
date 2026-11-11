<?php

declare(strict_types=1);

namespace GhostParse\Dav\Xml;

use DOMDocument;
use DOMElement;
use DOMNode;
use GhostParse\Dav\Http\HttpException;

/**
 * Helpers for safely reading request bodies.
 */
final class XmlUtil
{
    /**
     * Parse an XML request body. Document type declarations are rejected to
     * protect against entity expansion and XXE attacks.
     */
    public static function parse(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw new HttpException(400, 'Request body must contain an XML document');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (!$ok || $doc->documentElement === null) {
            throw new HttpException(400, 'Malformed XML request body');
        }
        if ($doc->doctype !== null) {
            throw new HttpException(400, 'Document type declarations are not allowed');
        }

        return $doc;
    }

    /**
     * Clark-notation name of an element.
     */
    public static function clark(DOMNode $node): string
    {
        return '{' . ($node->namespaceURI ?? '') . '}' . ($node->localName ?? $node->nodeName);
    }

    /**
     * Child elements, optionally filtered by Clark name.
     *
     * @return list<DOMElement>
     */
    public static function children(DOMElement $element, ?string $clark = null): array
    {
        $result = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && ($clark === null || self::clark($child) === $clark)) {
                $result[] = $child;
            }
        }

        return $result;
    }

    public static function child(DOMElement $element, string $clark): ?DOMElement
    {
        return self::children($element, $clark)[0] ?? null;
    }

    /**
     * Parse a DAV:prop element into a list of Clark names.
     *
     * @return list<string>
     */
    public static function propNames(DOMElement $prop): array
    {
        return array_map(static fn (DOMElement $e): string => self::clark($e), self::children($prop));
    }
}
