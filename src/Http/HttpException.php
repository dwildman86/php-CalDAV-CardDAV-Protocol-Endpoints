<?php

declare(strict_types=1);

namespace GhostParse\Dav\Http;

/**
 * An exception that maps directly onto an HTTP error response.
 *
 * A WebDAV precondition / postcondition (e.g. "{urn:ietf:params:xml:ns:caldav}valid-calendar-data")
 * may be attached and will be rendered inside a DAV:error body.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $status,
        string $message = '',
        private ?string $condition = null,
        private array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : Status::text($status), $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Clark-notation name of the violated DAV condition, if any.
     */
    public function getCondition(): ?string
    {
        return $this->condition;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
