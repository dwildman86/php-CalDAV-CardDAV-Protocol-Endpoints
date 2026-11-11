<?php

declare(strict_types=1);

namespace GhostParse\Dav\VObject;

/**
 * A BEGIN:X ... END:X block of an iCalendar or vCard object.
 */
final class Component
{
    /** @var list<Property> */
    public array $properties = [];

    /** @var list<Component> */
    public array $children = [];

    public function __construct(public readonly string $name)
    {
    }

    /**
     * @return list<Property>
     */
    public function getProperties(string $name): array
    {
        $name = strtoupper($name);

        return array_values(array_filter($this->properties, static fn (Property $p): bool => $p->name === $name));
    }

    public function getProperty(string $name): ?Property
    {
        return $this->getProperties($name)[0] ?? null;
    }

    /**
     * @return list<Component>
     */
    public function getComponents(?string $name = null): array
    {
        if ($name === null) {
            return $this->children;
        }
        $name = strtoupper($name);

        return array_values(array_filter($this->children, static fn (Component $c): bool => $c->name === $name));
    }

    public function serialize(): string
    {
        $out = 'BEGIN:' . $this->name . "\r\n";
        foreach ($this->properties as $property) {
            $out .= $property->serialize();
        }
        foreach ($this->children as $child) {
            $out .= $child->serialize();
        }

        return $out . 'END:' . $this->name . "\r\n";
    }
}
