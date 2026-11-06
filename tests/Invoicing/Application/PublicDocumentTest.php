<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\PublicDocument;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\LinkAudience;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the public page asks of a document by its kind and its total: a corrective
 * document below zero is a credit note, one above zero a debit note, and one of no
 * difference is neither.
 */
final class PublicDocumentTest extends TestCase
{
    /**
     * @return iterable<string, array{DocumentKind, int, bool, bool}>
     */
    public static function documents(): iterable
    {
        yield 'a credit note' => [DocumentKind::CorrectionDocument, -48400, true, false];
        yield 'a debit note' => [DocumentKind::CorrectionDocument, 48400, false, true];
        yield 'a correction of no difference' => [DocumentKind::CorrectionDocument, 0, false, false];
        yield 'an invoice' => [DocumentKind::Invoice, 121000, false, true];
        yield 'an invoice of nothing' => [DocumentKind::Invoice, 0, false, true];
        yield 'a tax document on payment' => [DocumentKind::PaymentTaxDocument, 121000, false, false];
    }

    #[DataProvider('documents')]
    public function testOnlyACorrectionAboveZeroOrAPayableKindAsksForPayment(DocumentKind $kind, int $amountMinor, bool $creditNote, bool $asksForPayment): void
    {
        $document = $this->document($kind, $amountMinor);

        self::assertSame($creditNote, $document->isCreditNote());
        self::assertSame($asksForPayment, $document->asksForPayment());
    }

    private function document(DocumentKind $kind, int $amountMinor): PublicDocument
    {
        return new PublicDocument('send-1', 'tenant-1', LinkAudience::Customer, 'Example Firm', '20260001', 'Example Customer', $amountMinor, Currency::CZK, new \DateTimeImmutable('2026-11-15'), DocumentLanguage::English, 'pdf/key', str_repeat('a', 64), PaymentMethod::BankTransfer, null, null, '20260001', null, PaymentState::Unpaid, 0, null, $kind, false, null, null, $amountMinor, 0, 0, false);
    }
}
