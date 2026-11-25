<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * What the writes of saved views refuse (ADR 0032), with a stable reason, the
 * field it is about (a filter's path, such as "conditions[1].op") and the kind
 * that decides the status.
 */
final class SavedViewRefused extends \DomainException implements Refused
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

    public static function notFound(): self
    {
        return new self('not_found', RefusalKind::NotFound, null, 'There is no view with this id that you can see. Find views with foresi_list_views.');
    }

    public static function forbidden(string $message): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, $message);
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
