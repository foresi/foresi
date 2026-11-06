<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\ExportState;
use PHPUnit\Framework\TestCase;

final class ExportStateTest extends TestCase
{
    public function testTheStateFollowsTheTwoMoments(): void
    {
        $exported = new \DateTimeImmutable('2026-11-06 10:00:00');
        $changed = new \DateTimeImmutable('2026-11-07 09:00:00');

        self::assertSame(ExportState::NotExported, ExportState::of(null, null));
        self::assertSame(ExportState::Exported, ExportState::of($exported, null));
        self::assertSame(ExportState::Changed, ExportState::of($exported, $changed));
    }
}
