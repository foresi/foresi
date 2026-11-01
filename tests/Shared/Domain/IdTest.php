<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class IdTest extends TestCase
{
    public function testGeneratesAVersion7Uuid(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Id::generate());
    }

    public function testIdsCreatedInOneProcessSortInCreationOrder(): void
    {
        $ids = array_map(Id::generate(...), range(1, 1000));
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($ids, $sorted);
    }

    public function testRejectsAnIntegerLookingValue(): void
    {
        self::assertFalse(Id::isValid('12'));
    }

    public function testAcceptsAGeneratedId(): void
    {
        self::assertTrue(Id::isValid(Id::generate()));
    }

    public function testRejectsAnotherUuidVersion(): void
    {
        self::assertFalse(Id::isValid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testRejectsAnUppercaseUuid(): void
    {
        self::assertFalse(Id::isValid(strtoupper(Id::generate())));
    }
}
