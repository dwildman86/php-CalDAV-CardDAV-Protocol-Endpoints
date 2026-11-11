<?php

declare(strict_types=1);

namespace GhostParse\Dav\VObject;

/**
 * A content line of an iCalendar / vCard object.
 */
final class Property
{
    /**
     * @param array<string, list<string>> $parameters upper-cased parameter name => values
     */
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly array $parameters = [],
        public readonly ?string $group = null,
    ) {
    }

    /**
     * Values of a parameter, or null if the parameter is absent.
     *
     * @return list<string>|null
     */
    public function getParameter(string $name): ?array
    {
        return $this->parameters[strtoupper($name)] ?? null;
    }

    public function getFirstParameter(string $name): ?string
    {
        return $this->getParameter($name)[0] ?? null;
    }

    /**
     * The value with TEXT escaping (RFC 5545 §3.3.11 / RFC 6350 §3.4) removed.
     */
    public function getTextValue(): string
    {
        return preg_replace_callback(
            '/\\\\([\\\\;,nN])/',
            static fn (array $m): string => match ($m[1]) {
                'n', 'N' => "\n",
                default => $m[1],
            },
            $this->value,
        ) ?? $this->value;
    }

    public function serialize(): string
    {
        $line = ($this->group !== null ? $this->group . '.' : '') . $this->name;
        foreach ($this->parameters as $name => $values) {
            $encoded = array_map(static function (string $v): string {
                $v = str_replace('"', "'", $v);

                return preg_match('/[;:,]/', $v) ? '"' . $v . '"' : $v;
            }, $values);
            $line .= ';' . $name . '=' . implode(',', $encoded);
        }
        $line .= ':' . $this->value;

        return self::fold($line);
    }

    /**
     * Fold a content line at 75 octets without splitting UTF-8 sequences.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line . "\r\n";
        }

        $out = '';
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            // Do not break inside a multi-byte UTF-8 sequence.
            while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
            $limit = 74;
        }

        return $out . $line . "\r\n";
    }
}
