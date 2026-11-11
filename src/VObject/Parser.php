<?php

declare(strict_types=1);

namespace GhostParse\Dav\VObject;

/**
 * Tolerant parser for the MIME directory format shared by iCalendar
 * (RFC 5545) and vCard (RFC 2426 / RFC 6350).
 */
final class Parser
{
    /**
     * Parse a single top-level component.
     *
     * @throws ParseException
     */
    public static function parse(string $data): Component
    {
        $components = self::parseAll($data);
        if (count($components) !== 1) {
            throw new ParseException('Expected exactly one top-level component, found ' . count($components));
        }

        return $components[0];
    }

    /**
     * Parse a stream that may contain several top-level components
     * (e.g. multiple concatenated vCards).
     *
     * @return list<Component>
     * @throws ParseException
     */
    public static function parseAll(string $data): array
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            $data = substr($data, 3);
        }
        if (preg_match('//u', $data) !== 1) {
            throw new ParseException('Data is not valid UTF-8');
        }

        $data = str_replace(["\r\n", "\r"], "\n", $data);
        // Unfold continuation lines (RFC 5545 §3.1).
        $data = preg_replace("/\n[ \t]/", '', $data) ?? $data;

        /** @var list<Component> $stack */
        $stack = [];
        $roots = [];
        $lineNo = 0;

        foreach (explode("\n", $data) as $line) {
            $lineNo++;
            if (trim($line) === '') {
                continue;
            }

            $property = self::parseLine($line, $lineNo);

            if ($property->name === 'BEGIN') {
                $stack[] = new Component(strtoupper(trim($property->value)));
                continue;
            }

            if ($property->name === 'END') {
                $name = strtoupper(trim($property->value));
                $current = array_pop($stack);
                if ($current === null || $current->name !== $name) {
                    throw new ParseException(sprintf('Unexpected END:%s on line %d', $name, $lineNo));
                }
                if ($stack === []) {
                    $roots[] = $current;
                } else {
                    $stack[count($stack) - 1]->children[] = $current;
                }
                continue;
            }

            if ($stack === []) {
                throw new ParseException(sprintf('Property outside of a component on line %d', $lineNo));
            }
            $stack[count($stack) - 1]->properties[] = $property;
        }

        if ($stack !== []) {
            throw new ParseException(sprintf('Missing END:%s', end($stack)->name));
        }
        if ($roots === []) {
            throw new ParseException('No component found');
        }

        return $roots;
    }

    private static function parseLine(string $line, int $lineNo): Property
    {
        $length = strlen($line);
        $i = 0;

        // Name (optionally prefixed with a group).
        while ($i < $length && $line[$i] !== ';' && $line[$i] !== ':') {
            $i++;
        }
        $fullName = substr($line, 0, $i);
        if ($fullName === '' || $i >= $length) {
            throw new ParseException(sprintf('Invalid content line %d', $lineNo));
        }
        $group = null;
        $dot = strrpos($fullName, '.');
        if ($dot !== false) {
            $group = substr($fullName, 0, $dot);
            $fullName = substr($fullName, $dot + 1);
        }
        if (!preg_match('/^[A-Za-z0-9-]+$/', $fullName)) {
            throw new ParseException(sprintf('Invalid property name "%s" on line %d', $fullName, $lineNo));
        }

        // Parameters.
        $parameters = [];
        while ($i < $length && $line[$i] === ';') {
            $i++;
            $start = $i;
            while ($i < $length && $line[$i] !== '=' && $line[$i] !== ';' && $line[$i] !== ':') {
                $i++;
            }
            $paramName = strtoupper(substr($line, $start, $i - $start));
            $values = [];
            if ($i < $length && $line[$i] === '=') {
                $i++;
                while (true) {
                    if ($i < $length && $line[$i] === '"') {
                        $end = strpos($line, '"', $i + 1);
                        if ($end === false) {
                            throw new ParseException(sprintf('Unterminated quoted parameter on line %d', $lineNo));
                        }
                        $values[] = substr($line, $i + 1, $end - $i - 1);
                        $i = $end + 1;
                    } else {
                        $start = $i;
                        while ($i < $length && $line[$i] !== ',' && $line[$i] !== ';' && $line[$i] !== ':') {
                            $i++;
                        }
                        $values[] = substr($line, $start, $i - $start);
                    }
                    if ($i < $length && $line[$i] === ',') {
                        $i++;
                        continue;
                    }
                    break;
                }
            } elseif ($paramName !== '') {
                // vCard 2.1 style bare parameter, e.g. "TEL;HOME:..." => TYPE=HOME
                $values[] = $paramName;
                $paramName = 'TYPE';
            }
            if ($paramName !== '') {
                $parameters[$paramName] = array_merge($parameters[$paramName] ?? [], $values);
            }
        }

        if ($i >= $length || $line[$i] !== ':') {
            throw new ParseException(sprintf('Missing ":" on line %d', $lineNo));
        }

        return new Property(strtoupper($fullName), substr($line, $i + 1), $parameters, $group);
    }
}
