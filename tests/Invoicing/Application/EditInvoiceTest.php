<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\Address;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatStatus;

final class EditInvoiceTest extends InvoiceTestCase
{
    public function testEditingChangesContentAndTotals(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edit->edit($invoice, $this->input([$this->line('2000.00')], ['description' => 'Changed', 'dueDate' => new \DateTimeImmutable('2026-12-01'), 'paymentMethod' => PaymentMethod::Card, 'bankAccount' => null]));
        $invoice = $this->reloaded($invoice);

        self::assertSame('Changed', $invoice->description());
        self::assertSame('2026-12-01', $invoice->dueDate()->format('Y-m-d'));
        self::assertSame(PaymentMethod::Card, $invoice->paymentMethod());
        self::assertNull($invoice->bankAccount());
        self::assertSame(200000, $invoice->net()->minor());
        self::assertSame(242000, $invoice->payable()->minor());
        self::assertSame('20260001', $invoice->number());
    }

    public function testLinesAreReplacedByDiff(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two'), $this->line('300.00', text: 'Three')]));

        $this->edit->edit($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('250.00', text: 'Two')]));
        $invoice = $this->reloaded($invoice);
        $lines = $this->store->lines($invoice);

        self::assertSame(['One', 'Two'], array_map(static fn (InvoiceLine $line): string => $line->text(), $lines));
        self::assertSame(35000, $invoice->net()->minor());

        $this->edit->edit($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('250.00', text: 'Two'), $this->line('1.00', text: 'New')]));
        self::assertCount(3, $this->store->lines($this->reloaded($invoice)));
    }

    public function testAnUnchangedLineWritesNoAuditEntry(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two')]));
        $before = $this->entriesOf($invoice);

        $this->edit->edit($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two')]));

        self::assertCount(\count($before), $this->entriesOf($invoice), 'saving unchanged writes nothing, dates included');
    }

    public function testEditingAfterTheContactChangedKeepsTheSnapshot(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->customer->rename('Renamed Customer');
        $address = $this->em->getRepository(PartyAddress::class)->findOneBy(['party' => $this->customer->id()]);
        self::assertInstanceOf(PartyAddress::class, $address);
        $address->relocate(new Address('Moved 9', 'Moved City', '900 00', 'CZ'));
        $this->em->flush();

        $this->edit->edit($invoice, $this->input(overrides: ['description' => 'Edited']));
        $invoice = $this->reloaded($invoice);

        self::assertSame('Example Customer a.s.', $invoice->customer()->name);
        self::assertSame('Customer Street 2', $invoice->customer()->address->street);
    }

    public function testACopyOnTheDocumentReplacesTheSnapshot(): void
    {
        $invoice = $this->issue->issue($this->input());
        $copy = new DocumentParty('Typed Name', null, null, new Address('Typed 1', 'Typed City', '300 00', 'CZ'), '', null, '', '', null, null);

        $this->edit->edit($invoice, $this->input(overrides: ['customerOnDocument' => $copy]));

        self::assertSame('Typed Name', $this->reloaded($invoice)->customer()->name);
    }

    public function testAChangedCustomerTakesANewSnapshot(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->customer(null, new Address('Other 3', 'Other City', '400 00', 'CZ'));
        $this->em->flush();

        $this->edit->edit($invoice, $this->input(overrides: ['customerId' => $other->id()]));
        $invoice = $this->reloaded($invoice);

        self::assertSame($other->id(), $invoice->customerParty()->id());
        self::assertSame('Other 3', $invoice->customer()->address->street);
        self::assertNull($invoice->customer()->vatId);
    }

    public function testAnOldDocumentOfAnArchivedFirmAndContactStaysEditable(): void
    {
        $invoice = $this->issue->issue($this->input());
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->customer, $this->firm, true);
        $this->firm->archive(new \DateTimeImmutable('2026-11-02'));
        $this->firm->update($this->firm->name(), $this->firm->registrationNumber(), null, $this->firm->address(), VatStatus::Registered, '', []);
        $this->em->flush();

        $this->edit->edit($invoice, $this->input(overrides: ['description' => 'Still editable']));

        self::assertSame('Still editable', $this->reloaded($invoice)->description());
        self::assertSame(self::IBAN, $this->reloaded($invoice)->bankAccount(), 'an account removed from the firm stays on its old document');
    }

    public function testEditingAfterTheFirmBecamePayerKeepsTheRegime(): void
    {
        $firm = new \App\Party\Domain\LegalEntity('Second Firm s.r.o.', new \App\Party\Domain\RegistrationNumber('CZ', '27074358'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Register', [self::IBAN]);
        $this->em->persist($firm);
        $this->em->flush();
        $invoice = $this->issue->issue($this->input([$this->line('121.00', PriceType::WithVat)], ['firmId' => $firm->id(), 'seriesId' => null]));

        $firm->update($firm->name(), $firm->registrationNumber(), \App\Party\Domain\VatId::fromString('CZ27074358'), $firm->address(), VatStatus::Registered, 'Register', [self::IBAN]);
        $this->em->flush();

        $this->edit->edit($invoice, $this->input([$this->line('242.00', PriceType::WithVat)], ['firmId' => $firm->id(), 'seriesId' => null]));
        $invoice = $this->reloaded($invoice);

        self::assertSame(VatRegime::NonPayer, $invoice->vatRegime());
        self::assertSame(0, $invoice->vat()->minor());
        self::assertSame(24200, $invoice->net()->minor());
        self::assertNull($this->store->lines($invoice)[0]->rate());
        self::assertNull($invoice->issuer()->vatId, 'the issuer stays as it was');
    }

    public function testEditingAfterTheFirmStoppedBeingAPayerKeepsTheRates(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->firm->update($this->firm->name(), $this->firm->registrationNumber(), null, $this->firm->address(), VatStatus::NotRegistered, '', [self::IBAN]);
        $this->em->flush();

        $this->edit->edit($invoice, $this->input([$this->line('2000.00')]));
        $invoice = $this->reloaded($invoice);

        self::assertSame(VatRegime::Payer, $invoice->vatRegime());
        self::assertSame(42000, $invoice->vat()->minor());
    }

    public function testAnEditPassesTheSameRules(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefusedFor('invoice.problem.no_lines', fn () => $this->edit->edit($invoice, $this->input([])));
        $this->assertRefusedFor('invoice.problem.line_rate_missing', fn () => $this->edit->edit($invoice, $this->input([$this->line(kind: null)])));
        self::assertSame(100000, $this->reloaded($invoice)->net()->minor());
    }

    public function testEveryChangeIsAuditedUnderTheInvoice(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two')]));
        $created = $this->entriesOf($invoice);

        $this->edit->edit($invoice, $this->input([$this->line('150.00', text: 'One')], ['description' => 'Changed', 'typedNumber' => '20260099']));
        $entries = \array_slice($this->entriesOf($invoice), \count($created));

        self::assertCount(3, array_filter($created, static fn (AuditEntry $entry): bool => AuditAction::Created === $entry->action()));
        $byClass = [];
        foreach ($entries as $entry) {
            $byClass[$entry->entityClass()][] = $entry->action();
        }
        self::assertSame([AuditAction::Updated], $byClass[Invoice::class]);
        self::assertEqualsCanonicalizing([AuditAction::Updated, AuditAction::Deleted], $byClass[InvoiceLine::class]);
        self::assertSame([AuditAction::Created], $byClass[AbandonedNumber::class]);
    }

    public function testTheFirmCannotChange(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = new \App\Party\Domain\LegalEntity('Second Firm s.r.o.', new \App\Party\Domain\RegistrationNumber('CZ', '27074358'), null, $this->firm->address(), VatStatus::NotRegistered, 'Register', [self::IBAN]);
        $this->em->persist($other);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.firm_locked', fn () => $this->edit->edit($invoice, $this->input(overrides: ['firmId' => $other->id()])));
        self::assertSame($this->firm->id(), $this->reloaded($invoice)->firm()->id());
        foreach (new \ReflectionClass(Invoice::class)->getMethods() as $method) {
            self::assertStringNotContainsString('firm', strtolower(preg_replace('/^firm$/', '', $method->getName()) ?? ''), 'nothing changes the firm');
        }
    }

    public function testChangingTheNumberBeforeDelivery(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edit->edit($invoice, $this->input(overrides: ['typedNumber' => '2026-0050']));
        $invoice = $this->reloaded($invoice);

        self::assertSame('2026-0050', $invoice->number());
        self::assertSame('20260050', $invoice->numberKey());
        self::assertSame('20260051', $this->issue->issue($this->input())->number(), 'a typed number ahead moves the counter');
    }

    public function testChangingTheSeriesAllocatesFromIt(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = new \App\Invoicing\Domain\Series($this->firm, 'Second', \App\Invoicing\Domain\SeriesFormat::fromString('B{NNN}'), \App\Invoicing\Domain\DocumentKind::Invoice);
        $this->catalog->add($other);
        $this->em->flush();

        $this->edit->edit($invoice, $this->input(overrides: ['seriesId' => $other->id()]));
        $invoice = $this->reloaded($invoice);

        self::assertSame('B001', $invoice->number());
        self::assertSame($other->id(), $invoice->series()->id());
        $this->assertRefusedFor('invoice.problem.number_taken', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '20260001'])));
    }

    public function testANumberAbandonedByAnEditIsNeverReused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->edit->edit($invoice, $this->input(overrides: ['typedNumber' => '20260002']));

        $this->assertRefusedFor('invoice.problem.number_taken', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '2026/0001'])));
        $this->assertRefusedFor('invoice.problem.number_taken', fn () => $this->edit->edit($this->reloaded($invoice), $this->input(overrides: ['typedNumber' => '20260001'])));
    }

    public function testAnAbandonedNumberIsSkippedByTheCounter(): void
    {
        $other = new \App\Invoicing\Domain\Series($this->firm, 'Second', \App\Invoicing\Domain\SeriesFormat::fromString('{YYYY}/{NNNN}'), \App\Invoicing\Domain\DocumentKind::Invoice);
        $this->catalog->add($other);
        $this->em->flush();
        $invoice = $this->issue->issue($this->input(overrides: ['seriesId' => $other->id(), 'typedNumber' => '2026/0001']));
        $this->edit->edit($invoice, $this->input(overrides: ['seriesId' => $other->id(), 'typedNumber' => '2026/0002']));

        self::assertSame('20260003', $this->issue->issue($this->input())->number(), '0001 is abandoned and 0002 belongs to the other series');
    }

    public function testRepunctuatingANumberAbandonsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edit->edit($invoice, $this->input(overrides: ['typedNumber' => '2026-0001']));

        self::assertSame('2026-0001', $this->reloaded($invoice)->number());
        self::assertSame(0, $this->em->getRepository(AbandonedNumber::class)->count());
    }

    public function testATakenNumberIsRefusedOnEdit(): void
    {
        $first = $this->issue->issue($this->input());
        $this->issue->issue($this->input());

        $this->assertRefusedFor('invoice.problem.number_taken', fn () => $this->edit->edit($first, $this->input(overrides: ['typedNumber' => '20260002'])));
    }

    public function testNumberAndSeriesAreLockedOnceDelivered(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->delivered->mark($invoice);
        $other = new \App\Invoicing\Domain\Series($this->firm, 'Second', \App\Invoicing\Domain\SeriesFormat::fromString('B{NNN}'), \App\Invoicing\Domain\DocumentKind::Invoice);
        $this->catalog->add($other);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.number_locked', fn () => $this->edit->edit($invoice, $this->input(overrides: ['typedNumber' => '20260050'])));
        $this->assertRefusedFor('invoice.problem.number_locked', fn () => $this->edit->edit($invoice, $this->input(overrides: ['typedNumber' => '2026-0001'])));
        $this->assertRefusedFor('invoice.problem.number_locked', fn () => $this->edit->edit($invoice, $this->input(overrides: ['seriesId' => $other->id()])));
        try {
            $invoice->changeNumber($other, 'B001');
            self::fail('The aggregate refuses it too.');
        } catch (\DomainException) {
        }

        $this->edit->edit($invoice, $this->input(overrides: ['description' => 'Content stays editable']));
        self::assertSame('Content stays editable', $this->reloaded($invoice)->description());
        self::assertSame('20260001', $this->reloaded($invoice)->number());
    }

    public function testATrashedInvoiceCannotBeEdited(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice, 'Wrong customer');

        $this->assertRefusedFor('invoice.problem.trashed', fn () => $this->edit->edit($invoice, $this->input(overrides: ['description' => 'x'])));
    }

    public function testTheTaxPointDecidesTheRateOnEditToo(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edit->edit($invoice, $this->input([$this->line(kind: VatRateKind::Reduced)]));

        self::assertSame('12.00', $this->store->lines($this->reloaded($invoice))[0]->rate()?->decimal());
    }

    public function testReverseChargeOnEditUsesTheCustomerOnTheDocument(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->customer->changeIdentifiers($this->customer->registrationNumber(), null);
        $this->em->flush();

        $this->edit->edit($invoice, $this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')]));

        self::assertSame(TaxTreatment::ReverseCharge, $this->store->lines($this->reloaded($invoice))[0]->treatment());
    }

    /**
     * @return list<AuditEntry> the whole history of the invoice, oldest first
     */
    private function entriesOf(Invoice $invoice): array
    {
        $this->em->clear();

        /** @var list<AuditEntry> $entries */
        $entries = $this->em->createQuery('SELECT e FROM '.AuditEntry::class.' e WHERE e.rootClass = :class AND e.rootId = :id ORDER BY e.id')
            ->setParameter('class', Invoice::class)
            ->setParameter('id', $invoice->id())
            ->getResult();

        return $entries;
    }
}
