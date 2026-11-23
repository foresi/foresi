<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\IssuePaymentTaxDocument;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\Savepoint;
use Foresi\Invoicing\Contract\DocumentKind;

/**
 * A payment received on an advance invoice gets its tax document in the same
 * transaction, from the one path every payment takes.
 */
final class PaymentTaxDocumentTest extends AdvanceTestCase
{
    public function testATaxDocumentIsNeverIssuedWithoutItsPayment(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('A tax document on payment is issued for a payment only.');

        $this->issue->issue($this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::PaymentTaxDocument]));
    }

    public function testTheSavepointNeedsATransaction(): void
    {
        $this->expectException(\LogicException::class);

        $savepoint = new \ReflectionProperty(IssuePaymentTaxDocument::class, 'savepoint')->getValue($this->taxDocumentService());
        self::assertInstanceOf(Savepoint::class, $savepoint);

        $savepoint->run(static fn (): null => null);
    }

    /**
     * The one the container wires, as phpstan's container inlines it.
     */
    private function taxDocumentService(): IssuePaymentTaxDocument
    {
        $service = new \ReflectionProperty(RecordPayment::class, 'taxDocuments')->getValue($this->record);
        self::assertInstanceOf(IssuePaymentTaxDocument::class, $service);

        return $service;
    }
}
