<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\DocumentExports;
use Foresi\Invoicing\Application\UnknownInvoice;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DocumentExportsTest extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    private DocumentExports $exports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exports = self::getContainer()->get(DocumentExports::class);
    }

    public function testClearingAnUnknownDocumentSaysSo(): void
    {
        $this->expectException(UnknownInvoice::class);

        $this->exports->clear('01963c5e-0000-7000-8000-00000000000a');
    }
}
