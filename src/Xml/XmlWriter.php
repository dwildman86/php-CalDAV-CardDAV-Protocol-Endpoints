<?php

declare(strict_types=1);

namespace GhostParse\Dav\Xml;

use Closure;
use DOMDocument;
use DOMElement;

/**
 * Small DOM-based writer that knows the common DAV namespace prefixes.
 */
final class XmlWriter
{
    private DOMDocument $doc;

    /** @var array<string, string> */
    private array $prefixes = Ns::PREFIXES;

    private int $counter = 0;

    public function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = false;
    }

    /**
     * Create the document root and declare all well-known namespaces on it.
     */
    public function root(string $clark): DOMElement
    {
        $root = $this->element($clark);
        foreach (Ns::PREFIXES as $ns => $prefix) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $ns);
        }
        $this->doc->appendChild($root);

        return $root;
    }

    public function element(string $clark, ?string $text = null): DOMElement
    {
        [$ns, $local] = Ns::split($clark);
        if ($ns === '') {
            $element = $this->doc->createElementNS(null, $local);
        } else {
            if (!isset($this->prefixes[$ns])) {
                $this->prefixes[$ns] = 'x' . $this->counter++;
            }
            $element = $this->doc->createElementNS($ns, $this->prefixes[$ns] . ':' . $local);
        }
        if ($text !== null && $text !== '') {
            $element->appendChild($this->doc->createTextNode($text));
        }

        return $element;
    }

    /**
     * Append a property value to an element. Values may be scalars (rendered
     * as text), null (empty element) or a Closure(XmlWriter, DOMElement).
     */
    public function writeValue(DOMElement $parent, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value instanceof Closure) {
            $value($this, $parent);

            return;
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $parent->appendChild($this->doc->createTextNode((string) $value));
    }

    public function document(): DOMDocument
    {
        return $this->doc;
    }

    public function toString(): string
    {
        $xml = (string) $this->doc->saveXML();

        // Elements created detached carry their own namespace declarations;
        // a clean re-parse drops those that are redundant with the root's.
        $clean = new DOMDocument('1.0', 'UTF-8');
        if ($clean->loadXML($xml, LIBXML_NSCLEAN | LIBXML_NONET)) {
            return (string) $clean->saveXML();
        }

        return $xml;
    }
}
