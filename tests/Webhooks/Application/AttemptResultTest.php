<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Application;

use App\Webhooks\Application\AttemptResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttemptResultTest extends TestCase
{
    #[DataProvider('statuses')]
    public function testOnlyA2xxAnswerSucceeds(?int $status, bool $succeeded): void
    {
        self::assertSame($succeeded, new AttemptResult($status, 10)->succeeded);
    }

    /**
     * @return iterable<string, array{?int, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'no answer' => [null, false];
        yield 'informational' => [101, false];
        yield '199' => [199, false];
        yield '200' => [200, true];
        yield '299' => [299, true];
        yield '300' => [300, false];
        yield '410' => [410, false];
    }

    public function testOnly410IsGone(): void
    {
        self::assertTrue(new AttemptResult(410, 10)->gone);
        self::assertFalse(new AttemptResult(404, 10)->gone);
    }
}
