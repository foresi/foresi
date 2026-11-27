<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Application\Query;

use Foresi\ReceivedDocuments\Application\Query\ReceivedDocumentRow;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentState;
use PHPUnit\Framework\TestCase;

final class ReceivedDocumentRowTest extends TestCase
{
    public function testARowIsTrashedWhenItHasADayOfDeletion(): void
    {
        self::assertFalse($this->row(null)->isTrashed());
        self::assertTrue($this->row(new \DateTimeImmutable('2026-11-10'))->isTrashed());
    }

    private function row(?\DateTimeImmutable $deletedAt): ReceivedDocumentRow
    {
        return new ReceivedDocumentRow('id', ReceivedDocumentState::Recorded, ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, 'R20260001', 'Supplier Example s.r.o.', 'FV-1', null, null, null, null, null, new \DateTimeImmutable('2026-11-01'), $deletedAt, null, null);
    }
}
