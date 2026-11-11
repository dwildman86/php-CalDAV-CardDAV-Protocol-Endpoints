<?php

declare(strict_types=1);

namespace GhostParse\Dav\Http;

/**
 * Minimal immutable HTTP request representation.
 */
final class Request
{
    private string $method;

    /** @var array<string, string> lower-cased header name => value */
    private array $headers = [];

    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        string $method,
        private string $uri,
        array $headers = [],
        private string $body = '',
    ) {
        $this->method = strtoupper($method);
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
    }

    /**
     * Build a request from PHP's superglobals.
     */
    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }

        // Many SAPIs strip the Authorization header; recover it where possible.
        if (!isset($headers['AUTHORIZATION'])) {
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['PHP_AUTH_USER']) && is_string($_SERVER['PHP_AUTH_USER'])) {
                $password = isset($_SERVER['PHP_AUTH_PW']) && is_string($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
                $headers['AUTHORIZATION'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $password);
            }
        }

        $body = file_get_contents('php://input');

        return new self(
            is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/',
            $headers,
            $body === false ? '' : $body,
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * The (still percent-encoded) path component of the request URI.
     */
    public function getPath(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
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
}
