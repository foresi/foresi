<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Domain\DealConflict;
use Foresi\Sales\Domain\InvalidField;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * Why Sales refused a read or a write, as every surface answers it (ADR 0032):
 * the reason, its kind and the field it concerns.
 */
final class SalesRefused extends \DomainException implements Refused
{
    /**
     * @param array<string, bool|float|int|list<bool|float|int|string>|string|null> $details
     */
    private function __construct(private readonly string $reason, private readonly RefusalKind $kind, private readonly ?string $field, string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $field, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message);
    }

    /**
     * @param array<string, bool|float|int|list<bool|float|int|string>|string|null> $details what the caller needs to resolve it, such as the id of the record in the way
     */
    public static function conflict(string $reason, string $message, ?string $field = null, array $details = []): self
    {
        return new self($reason, RefusalKind::Conflict, $field, $message, $details);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', RefusalKind::NotFound, null, $message);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, 'Only the owners and members of the organisation work with its deals.');
    }

    /**
     * Runs a change of the domain and turns what it refuses into a refusal.
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     *
     * @throws self
     */
    public static function unless(callable $change): mixed
    {
        try {
            return $change();
        } catch (InvalidField $invalid) {
            throw self::invalid($invalid->reason, $invalid->field, $invalid->getMessage());
        } catch (DealConflict $conflict) {
            throw self::conflict($conflict->reason, $conflict->getMessage());
        }
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return $this->kind;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function details(): array
    {
        return $this->details;
    }
}
