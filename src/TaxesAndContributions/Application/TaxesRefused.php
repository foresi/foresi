<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * What the use cases of taxes and contributions refuse (ADR 0032), with a
 * stable reason, the input field it is about and the kind that decides the
 * status.
 */
final class TaxesRefused extends \DomainException implements Refused
{
    private function __construct(private readonly string $reason, private readonly RefusalKind $kind, private readonly ?string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $field, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message);
    }

    public static function conflict(string $reason, string $message): self
    {
        return new self($reason, RefusalKind::Conflict, null, $message);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', RefusalKind::NotFound, null, $message);
    }

    /**
     * The profile holds personal data (B7): a member, a guest and anyone
     * signed out are refused alike.
     */
    public static function ownerOnly(): self
    {
        return new self('owner_only', RefusalKind::Forbidden, null, 'Only the owner of the organisation reads and changes the taxes and contributions: the owner profile holds personal data.');
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
        return [];
    }
}
