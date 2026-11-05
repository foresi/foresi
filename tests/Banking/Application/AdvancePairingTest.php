<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Tests\Banking\Support\BankingTestCase;

/**
 * A bank payment of an advance invoice gets its tax document in the transaction
 * that pairs it, as a payment recorded by hand does.
 */
final class AdvancePairingTest extends BankingTestCase
{
    public function testAnAdvancePairedFromTheBankGetsItsTaxDocument(): void
    {
        $advance = $this->issue->issue($this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::Advance, 'variableSymbol' => '920260001']));
        $movement = $this->recordMovement->record($this->incoming('1210.00', '920260001', bookedOn: '2026-11-19'), self::verified());

        $payment = $this->pairing->auto($movement->id());

        self::assertNotNull($payment);
        $this->em->clear();
        /** @var list<Invoice> $documents */
        $documents = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.advanceInvoiceId = :advance')->setParameter('advance', $advance->id())->getResult();
        self::assertCount(1, $documents);
        self::assertSame(DocumentKind::PaymentTaxDocument, $documents[0]->kind());
        self::assertSame($payment->id(), $documents[0]->paymentId());
        self::assertSame('2026-11-19', $documents[0]->taxPointDate()?->format('Y-m-d'));
        self::assertSame($payment->id(), $this->storedMovement($movement->id())->paymentId());
    }

    public function testATaxDocumentIsNeverACandidate(): void
    {
        $advance = $this->issue->issue($this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::Advance, 'variableSymbol' => '920260001']));
        $this->pairing->auto($this->recordMovement->record($this->incoming('1210.00', '920260001'), self::verified())->id());

        // A second transfer with the advance's symbol: the advance is paid, its tax document asks for nothing.
        $movement = $this->recordMovement->record($this->incoming('1000.00', '920260001', bookedOn: '2026-11-19'), self::verified());

        self::assertSame([], $this->matcher->candidates($this->storedMovement($movement->id())));
        self::assertNull($this->pairing->auto($movement->id()));
        self::assertSame(1, $this->paymentCount());
        $advanceId = $advance->id();
        $document = $this->em->getConnection()->fetchOne('SELECT id FROM invoice WHERE advance_invoice_id = ?', [$advanceId]);
        self::assertIsString($document);
        $this->expectException(PaymentRefused::class);
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-20'), $this->czk('10.00'), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($document, $this->czk('10.00'))]));
    }
}
