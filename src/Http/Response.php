<?php

declare(strict_types=1);

namespace GhostParse\Dav\Http;

/**
 * Minimal mutable HTTP response.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $status = 200,
        array $headers = [],
        private string $body = '',
    ) {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function setHeader(string $name, string $value): void
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($this->headers[$existing]);
            }
        }
        $this->headers[$name] = $value;
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp($existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    /**
     * Emit the response through the PHP SAPI.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            header(sprintf('HTTP/1.1 %d %s', $this->status, Status::text($this->status)), true, $this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $this->body;
    }
}
