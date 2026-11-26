<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * Why the fleet refuses a change; every surface answers it through the shared
 * refusal contract (ADR 0032).
 */
final class VehicleRefused extends \DomainException implements Refused
{
    private function __construct(
        private readonly string $reason,
        private readonly RefusalKind $kind,
        private readonly ?string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function blank(string $field): self
    {
        return new self($field.'_required', RefusalKind::Invalid, $field, \sprintf('The %s of a vehicle cannot be empty.', $field));
    }

    public static function plateTaken(string $plate): self
    {
        return new self('plate_taken', RefusalKind::Conflict, 'plate', \sprintf('The fleet already has a vehicle with the plate %s.', $plate));
    }

    public static function full(int $most): self
    {
        return new self('fleet_full', RefusalKind::Conflict, null, \sprintf('The fleet holds at most %d vehicles.', $most));
    }

    public static function notStaff(): self
    {
        return new self('not_staff', RefusalKind::Forbidden, null, 'Only the owners and members of the organisation keep its fleet.');
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
