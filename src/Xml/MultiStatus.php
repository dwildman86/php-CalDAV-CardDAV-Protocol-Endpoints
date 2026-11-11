<?php

declare(strict_types=1);

namespace GhostParse\Dav\Xml;

use DOMElement;
use GhostParse\Dav\Http\Response;
use GhostParse\Dav\Http\Status;

/**
 * Builder for DAV:multistatus (207) responses.
 */
final class MultiStatus
{
    private XmlWriter $writer;

    private DOMElement $root;

    private int $count = 0;

    public function __construct()
    {
        $this->writer = new XmlWriter();
        $this->root = $this->writer->root('{DAV:}multistatus');
    }

    /**
     * Add a response element containing one propstat per status code.
     *
     * @param array<int, array<string, mixed>> $propStats status => [clark name => value]
     */
    public function addPropResponse(string $href, array $propStats): void
    {
        $response = $this->writer->element('{DAV:}response');
        $response->appendChild($this->writer->element('{DAV:}href', $href));

        foreach ($propStats as $status => $properties) {
            if ($properties === []) {
                continue;
            }
            $propStat = $this->writer->element('{DAV:}propstat');
            $prop = $this->writer->element('{DAV:}prop');
            foreach ($properties as $name => $value) {
                $element = $this->writer->element($name);
                $this->writer->writeValue($element, $value);
                $prop->appendChild($element);
            }
            $propStat->appendChild($prop);
            $propStat->appendChild($this->writer->element('{DAV:}status', Status::line($status)));
            $response->appendChild($propStat);
        }

        $this->root->appendChild($response);
        $this->count++;
    }

    /**
     * Add a response element that only carries a status (e.g. 404 for deleted members).
     */
    public function addStatusResponse(string $href, int $status): void
    {
        $response = $this->writer->element('{DAV:}response');
        $response->appendChild($this->writer->element('{DAV:}href', $href));
        $response->appendChild($this->writer->element('{DAV:}status', Status::line($status)));
        $this->root->appendChild($response);
        $this->count++;
    }

    public function setSyncToken(string $token): void
    {
        $this->root->appendChild($this->writer->element('{DAV:}sync-token', $token));
    }

    public function count(): int
    {
        return $this->count;
    }

    public function toResponse(): Response
    {
        return new Response(207, ['Content-Type' => 'application/xml; charset=utf-8'], $this->writer->toString());
    }
}
