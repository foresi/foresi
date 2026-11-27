<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * The note the test asked to fail.
 */
final class FixtureNoteRefused extends \DomainException implements Refused
{
    public function __construct(private readonly string $field = 'fail')
    {
        parent::__construct('The note failed, as the test asked.');
    }

    public function reason(): string
    {
        return 'fixture_failed';
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::Conflict;
    }

    public function field(): string
    {
        return $this->field;
    }

    public function details(): array
    {
        return [];
    }
}
