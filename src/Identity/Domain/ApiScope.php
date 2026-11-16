<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * What a personal API token may do: read or write everything ("read",
 * "write"), or read or write one resource a module declares, such as
 * "contacts:read" or "contacts:write" (ADR 0032). A write scope grants the
 * matching reads: "write" every scope, "read" every read, "contacts:write"
 * "contacts:read". Which resources exist is the module registry's to say; this
 * only knows the shape.
 */
final readonly class ApiScope
{
    public const string READ = 'read';
    public const string WRITE = 'write';
    private const string SHAPE = '/^(read|write|[a-z][a-z0-9_]{0,39}:(read|write))$/';

    private function __construct(public string $value)
    {
    }

    public static function read(): self
    {
        return new self(self::READ);
    }

    public static function write(): self
    {
        return new self(self::WRITE);
    }

    /**
     * @throws \InvalidArgumentException when the value is not a scope
     */
    public static function from(string $value): self
    {
        return self::tryFrom($value) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not an API scope.', $value));
    }

    public static function tryFrom(string $value): ?self
    {
        return 1 === preg_match(self::SHAPE, $value) ? new self($value) : null;
    }

    public static function of(string $resource, bool $write): self
    {
        return self::from($resource.':'.($write ? 'write' : 'read'));
    }

    /**
     * The resource of a scope such as "contacts:write"; null for "read" and "write".
     */
    public function resource(): ?string
    {
        $colon = strpos($this->value, ':');

        return false === $colon ? null : substr($this->value, 0, $colon);
    }

    public function writes(): bool
    {
        return str_ends_with($this->value, self::WRITE);
    }

    /**
     * Whether a token with this scope may do what the other one allows.
     */
    public function grants(self $needed): bool
    {
        if ($this->value === $needed->value || self::WRITE === $this->value) {
            return true;
        }
        if (self::READ === $this->value) {
            return !$needed->writes();
        }

        return $this->writes() && !$needed->writes() && $this->resource() === $needed->resource();
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
