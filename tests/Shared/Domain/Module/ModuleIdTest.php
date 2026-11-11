<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Domain\Module;

use App\Shared\Domain\Module\InvalidManifest;
use App\Shared\Domain\Module\ModuleId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonKebabIds(): iterable
    {
        yield 'uppercase' => ['Party'];
        yield 'underscore' => ['party_x'];
        yield 'empty' => [''];
        yield 'leading digit' => ['1party'];
    }

    #[DataProvider('nonKebabIds')]
    public function testRejectsNonKebabIds(string $id): void
    {
        $this->expectException(InvalidManifest::class);

        ModuleId::of($id);
    }

    public function testKeepsAKebabId(): void
    {
        self::assertSame('exchange-rate', ModuleId::of('exchange-rate')->value);
    }
}
