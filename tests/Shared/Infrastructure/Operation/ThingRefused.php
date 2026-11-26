<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

final class ThingRefused extends \DomainException implements Refused
{
    public function __construct(private readonly RefusalKind $kind, private readonly string $reason, private readonly ?string $field = null)
    {
        parent::__construct('Refused for the test.');
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
        return 'duplicate' === $this->reason ? ['existing_ids' => ['x']] : [];
    }
}
