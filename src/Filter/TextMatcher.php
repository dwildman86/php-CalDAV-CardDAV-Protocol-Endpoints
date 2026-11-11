<?php

declare(strict_types=1);

namespace GhostParse\Dav\Filter;

use DOMElement;
use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\Xml\Ns;

/**
 * Evaluates CALDAV:text-match / CARDDAV:text-match elements.
 */
final class TextMatcher
{
    private const COLLATIONS = ['i;ascii-casemap', 'i;octet', 'i;unicode-casemap'];

    public static function matches(string $value, DOMElement $textMatch, string $defaultCollation, string $defaultMatchType = 'contains'): bool
    {
        $needle = $textMatch->textContent;
        $collation = $textMatch->getAttribute('collation') ?: $defaultCollation;
        $matchType = $textMatch->getAttribute('match-type') ?: $defaultMatchType;
        $negate = strtolower($textMatch->getAttribute('negate-condition')) === 'yes';

        if (!in_array($collation, self::COLLATIONS, true)) {
            $condition = $textMatch->namespaceURI === Ns::CARDDAV
                ? '{' . Ns::CARDDAV . '}supported-collation'
                : '{' . Ns::CALDAV . '}supported-collation';
            throw new HttpException(403, sprintf('Unsupported collation "%s"', $collation), $condition);
        }

        if ($collation === 'i;ascii-casemap') {
            $value = strtolower($value);
            $needle = strtolower($needle);
        } elseif ($collation === 'i;unicode-casemap') {
            $value = self::unicodeFold($value);
            $needle = self::unicodeFold($needle);
        }

        $result = match ($matchType) {
            'equals' => $value === $needle,
            'starts-with' => str_starts_with($value, $needle),
            'ends-with' => str_ends_with($value, $needle),
            'contains' => str_contains($value, $needle),
            default => throw new HttpException(400, sprintf('Unsupported match-type "%s"', $matchType)),
        };

        return $negate ? !$result : $result;
    }

    private static function unicodeFold(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
