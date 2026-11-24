<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Application;

use Foresi\Documents\Application\DocumentFilter;
use Foresi\Tests\Documents\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The text search of the document list: the title, whatever the case, with the
 * wildcards of LIKE taken as the characters they are.
 */
final class DocumentSearchTest extends DocumentsTestCase
{
    #[Test]
    public function theListIsFoundByTheTitleWhateverTheCase(): void
    {
        $this->draft();

        $found = fn (string $text): int => \count($this->store()->documents(new DocumentFilter(text: $text)));
        self::assertSame(1, $found(''));
        self::assertSame(1, $found('nda WITH'));
        self::assertSame(1, $found('  example client  '));
        self::assertSame(0, $found('%'));
        self::assertSame(0, $found('n_a'));
        self::assertSame(0, $found('lease'));
        self::assertSame([], $this->store()->documents(new DocumentFilter(trashed: true, text: 'nda')), 'the trash is another list');
    }
}
