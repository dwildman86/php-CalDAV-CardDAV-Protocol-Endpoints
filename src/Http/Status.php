<?php

declare(strict_types=1);

namespace GhostParse\Dav\Http;

/**
 * HTTP status reason phrases (including WebDAV extensions).
 */
final class Status
{
    private const TEXTS = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        207 => 'Multi-Status',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        415 => 'Unsupported Media Type',
        423 => 'Locked',
        424 => 'Failed Dependency',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        507 => 'Insufficient Storage',
    ];

    public static function text(int $code): string
    {
        return self::TEXTS[$code] ?? 'Unknown';
    }

    public static function line(int $code): string
    {
        return sprintf('HTTP/1.1 %d %s', $code, self::text($code));
    }
}
