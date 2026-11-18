<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Foresi\Party\Application\ImportedBy;
use Foresi\Party\Infrastructure\CollectedContactImports;
use PHPUnit\Framework\TestCase;

final class CollectedContactImportsTest extends TestCase
{
    private const string IMPORT = '019a0000-0000-7000-8000-000000000001';

    public function testWithoutAnImportModuleNoImportExistsAndNarrowingShowsNothing(): void
    {
        $imports = new CollectedContactImports([]);

        self::assertNull($imports->fileName(self::IMPORT));
        self::assertSame(['1 = 0', []], $imports->condition(new ImportedBy(self::IMPORT, 'create'), 'p.id'));
    }

    public function testAMalformedFilterIsNoFilter(): void
    {
        self::assertNull(ImportedBy::tryFrom('not-an-id', 'create'));
        self::assertNull(ImportedBy::tryFrom(self::IMPORT, 'skip'));
        self::assertEquals(new ImportedBy(self::IMPORT, 'archive'), ImportedBy::tryFrom(self::IMPORT, 'archive'));
    }
}
