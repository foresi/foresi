<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * What the Activities use cases refuse (ADR 0032).
 */
final class ActivitiesRefused extends \DomainException implements Refused
{
    /**
     * @param array<string, scalar|list<scalar>|null> $details
     */
    private function __construct(
        private readonly string $reason,
        private readonly RefusalKind $kind,
        private readonly ?string $field,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $details
     */
    public static function invalid(string $reason, string $field, string $message, array $details = []): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message, $details);
    }

    public static function conflict(string $reason, string $message): self
    {
        return new self($reason, RefusalKind::Conflict, null, $message);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', RefusalKind::NotFound, null, $message);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, 'Only the owners and members of the organisation work with its activities.');
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
