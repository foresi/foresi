<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application;

use Foresi\Shared\Application\RefusalKind;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP status each kind of refusal is answered with (ADR 0032).
 */
final class RefusalKindTest extends TestCase
{
    public function testEachKindHasItsStatus(): void
    {
        self::assertSame(
            ['invalid' => 400, 'forbidden' => 403, 'not_found' => 404, 'conflict' => 409, 'unavailable' => 503],
            array_combine(
                array_map(static fn (RefusalKind $kind): string => $kind->value, RefusalKind::cases()),
                array_map(static fn (RefusalKind $kind): int => $kind->status(), RefusalKind::cases()),
            ),
        );
    }
}
