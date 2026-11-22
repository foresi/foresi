<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * What a change of the firm's VAT statuses refuses (ADR 0032), with a stable
 * reason, the input field it is about and the kind that decides the status.
 */
final class VatStatusRefused extends \DomainException implements Refused
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
     * The VAT statuses are settings of the firm: a member, a guest and anyone
     * signed out are refused alike, as on the firm settings page.
     */
    public static function ownerOnly(): self
    {
        return new self('owner_only', RefusalKind::Forbidden, null, 'Only the owner of the organisation changes the VAT statuses of the firm.');
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
