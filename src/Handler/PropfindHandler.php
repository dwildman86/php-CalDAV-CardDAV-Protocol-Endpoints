<?php

declare(strict_types=1);

namespace GhostParse\Dav\Handler;

use GhostParse\Dav\Http\HttpException;
use GhostParse\Dav\Http\Request;
use GhostParse\Dav\Http\Response;
use GhostParse\Dav\Routing\Location;
use GhostParse\Dav\Xml\MultiStatus;
use GhostParse\Dav\Xml\XmlUtil;

/**
 * PROPFIND (RFC 4918 §9.1).
 */
final class PropfindHandler
{
    public function __construct(private Context $context)
    {
    }

    public function handle(Request $request, Location $location): Response
    {
        $node = $this->context->tree->get($location) ?? throw new HttpException(404, 'Not Found');

        [$mode, $names] = self::parseBody($request->getBody());
        $depth = self::depth($request, 1);
        $minimal = self::wantsMinimal($request);

        $nodes = [$node];
        if ($depth > 0 && $location->type->isCollection()) {
            array_push($nodes, ...$this->context->tree->children($node));
        }

        $multiStatus = new MultiStatus();
        foreach ($nodes as $current) {
            if ($mode === 'propname') {
                $multiStatus->addPropResponse($current->href, [
                    200 => array_fill_keys($this->context->properties->allPropNames($current), null),
                ]);
                continue;
            }

            if ($mode === 'allprop') {
                $requested = array_values(array_unique(array_merge(
                    $this->context->properties->allPropNames($current),
                    $names,
                )));
            } else {
                $requested = $names;
            }

            [$found, $notFound] = $this->context->properties->resolve($current, $requested);
            $multiStatus->addPropResponse($current->href, [
                200 => $found,
                404 => $minimal ? [] : array_fill_keys($notFound, null),
            ]);
        }

        $response = $multiStatus->toResponse();
        if ($minimal) {
            $response->setHeader('Preference-Applied', 'return=minimal');
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: list<string>} [mode, property names]
     */
    public static function parseBody(string $body): array
    {
        if (trim($body) === '') {
            return ['allprop', []];
        }

        $root = XmlUtil::parse($body)->documentElement;
        if ($root === null || XmlUtil::clark($root) !== '{DAV:}propfind') {
            throw new HttpException(400, 'Expected a DAV:propfind request body');
        }

        if (XmlUtil::child($root, '{DAV:}propname') !== null) {
            return ['propname', []];
        }
        if (XmlUtil::child($root, '{DAV:}allprop') !== null) {
            $include = XmlUtil::child($root, '{DAV:}include');

            return ['allprop', $include !== null ? XmlUtil::propNames($include) : []];
        }
        $prop = XmlUtil::child($root, '{DAV:}prop');
        if ($prop !== null) {
            return ['prop', XmlUtil::propNames($prop)];
        }

        return ['allprop', []];
    }

    /**
     * Parse the Depth header. "infinity" is treated as 1 because the
     * hierarchy below a calendar or address book home is never deeper.
     */
    public static function depth(Request $request, int $default): int
    {
        $depth = strtolower(trim((string) $request->getHeader('Depth')));

        return match ($depth) {
            '0' => 0,
            '1', 'infinity' => 1,
            default => $default,
        };
    }

    public static function wantsMinimal(Request $request): bool
    {
        $prefer = strtolower((string) $request->getHeader('Prefer'));
        if (str_contains($prefer, 'return=minimal')) {
            return true;
        }

        return strtolower(trim((string) $request->getHeader('Brief'))) === 't';
    }
}
