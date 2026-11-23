<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Pdf;

use Foresi\Invoicing\Application\InvoicePdf;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;

final class InvoicePdfTest extends InvoiceTestCase
{
    private InvoicePdf $pdf;
    private string $imageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdf = self::getContainer()->get(InvoicePdf::class);
        $root = self::getContainer()->getParameter('app.firm_image_dir');
        $this->imageDir = $root;
    }

    protected function tearDown(): void
    {
        $files = glob($this->imageDir.'/*');
        array_map(unlink(...), false === $files ? [] : $files);
        @rmdir($this->imageDir);
        parent::tearDown();
    }

    public function testTheFiguresOfTheFontAreTabular(): void
    {
        foreach (['intertnum', 'intertnumb'] as $face) {
            /** @var array{cw: array<int, int>} $font */
            $font = json_decode((string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/src/Shared/resources/pdf-fonts/'.$face.'.json'), true, flags: \JSON_THROW_ON_ERROR);
            self::assertCount(1, array_unique(array_map(static fn (int $digit): int => $font['cw'][48 + $digit], range(0, 9))));
        }
    }
}
