<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\Address;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class EditInvoiceTest extends InvoiceTestCase
{
    public function testEditingChangesContentAndTotals(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edited($invoice, $this->input([$this->line('2000.00')], ['description' => 'Changed', 'dueDate' => new \DateTimeImmutable('2026-12-01'), 'paymentMethod' => PaymentMethod::Card, 'bankAccount' => null]));
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

        $this->edited($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('250.00', text: 'Two')]));
        $invoice = $this->reloaded($invoice);
        $lines = $this->store->lines($invoice);

        self::assertSame(['One', 'Two'], array_map(static fn (InvoiceLine $line): string => $line->text(), $lines));
        self::assertSame(35000, $invoice->net()->minor());

        $this->edited($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('250.00', text: 'Two'), $this->line('1.00', text: 'New')]));
        self::assertCount(3, $this->store->lines($this->reloaded($invoice)));
    }

    public function testAnUnchangedLineWritesNoAuditEntry(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two')]));
        $before = $this->entriesOf($invoice);

        $this->edited($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two')]));

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

        $this->edited($invoice, $this->input(overrides: ['description' => 'Edited']));
        $invoice = $this->reloaded($invoice);

        self::assertSame('Example Customer a.s.', $invoice->customer()->name);
        self::assertSame('Customer Street 2', $invoice->customer()->address->street);
    }

    public function testACopyOnTheDocumentReplacesTheSnapshot(): void
    {
        $invoice = $this->issue->issue($this->input());
        $copy = new DocumentParty('Typed Name', null, null, new Address('Typed 1', 'Typed City', '300 00', 'CZ'), '', null, '', '', null, null);

        $this->edited($invoice, $this->input(overrides: ['customerOnDocument' => $copy]));

        self::assertSame('Typed Name', $this->reloaded($invoice)->customer()->name);
    }

    public function testAChangedCustomerTakesANewSnapshot(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->customer(null, new Address('Other 3', 'Other City', '400 00', 'CZ'));
        $this->em->flush();

        $this->edited($invoice, $this->input(overrides: ['customerId' => $other->id()]));
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

        $this->edited($invoice, $this->input(overrides: ['description' => 'Still editable']));

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

        $this->edited($invoice, $this->input([$this->line('242.00', PriceType::WithVat)], ['firmId' => $firm->id(), 'seriesId' => null]));
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

        $this->edited($invoice, $this->input([$this->line('2000.00')]));
        $invoice = $this->reloaded($invoice);

        self::assertSame(VatRegime::Payer, $invoice->vatRegime());
        self::assertSame(42000, $invoice->vat()->minor());
    }

    public function testAnEditPassesTheSameRules(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefusedFor('invoice.problem.no_lines', fn () => $this->edited($invoice, $this->input([])));
        $this->assertRefusedFor('invoice.problem.line_rate_missing', fn () => $this->edited($invoice, $this->input([$this->line(kind: null)])));
        self::assertSame(100000, $this->reloaded($invoice)->net()->minor());
    }

    public function testEveryChangeIsAuditedUnderTheInvoice(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two')]));
        $created = $this->entriesOf($invoice);

        $this->edited($invoice, $this->input([$this->line('150.00', text: 'One')], ['description' => 'Changed', 'typedNumber' => '20260099']));
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

        $this->assertRefusedFor('invoice.problem.firm_locked', fn () => $this->edited($invoice, $this->input(overrides: ['firmId' => $other->id()])));
        self::assertSame($this->firm->id(), $this->reloaded($invoice)->firm()->id());
        foreach (new \ReflectionClass(Invoice::class)->getMethods() as $method) {
            self::assertStringNotContainsString('firm', strtolower(preg_replace('/^firm$/', '', $method->getName()) ?? ''), 'nothing changes the firm');
        }
    }

    public function testChangingTheNumberBeforeDelivery(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edited($invoice, $this->input(overrides: ['typedNumber' => '2026-0050']));
        $invoice = $this->reloaded($invoice);

        self::assertSame('2026-0050', $invoice->number());
        self::assertSame('20260050', $invoice->numberKey());
        self::assertSame('20260051', $this->issue->issue($this->input())->number(), 'a typed number ahead moves the counter');
    }

    public function testChangingTheSeriesAllocatesFromIt(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = new Series($this->firm, 'Second', SeriesFormat::fromString('B{NNN}'), DocumentKind::Invoice);
        $this->catalog->add($other);
        $this->em->flush();

        $this->edited($invoice, $this->input(overrides: ['seriesId' => $other->id()]));
        $invoice = $this->reloaded($invoice);

        self::assertSame('B001', $invoice->number());
        self::assertSame($other->id(), $invoice->series()->id());
        $this->assertRefusedFor('invoice.problem.number_taken', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '20260001'])));
    }

    public function testANumberAbandonedByAnEditIsNeverReused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->edited($invoice, $this->input(overrides: ['typedNumber' => '20260002']));

        $this->assertRefusedFor('invoice.problem.number_taken', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '2026/0001'])));
        $this->assertRefusedFor('invoice.problem.number_taken', fn () => $this->edited($this->reloaded($invoice), $this->input(overrides: ['typedNumber' => '20260001'])));
    }

    public function testAnAbandonedNumberIsSkippedByTheCounter(): void
    {
        $other = new Series($this->firm, 'Second', SeriesFormat::fromString('{YYYY}/{NNNN}'), DocumentKind::Invoice);
        $this->catalog->add($other);
        $this->em->flush();
        $invoice = $this->issue->issue($this->input(overrides: ['seriesId' => $other->id(), 'typedNumber' => '2026/0001']));
        $this->edited($invoice, $this->input(overrides: ['seriesId' => $other->id(), 'typedNumber' => '2026/0002']));

        self::assertSame('20260003', $this->issue->issue($this->input())->number(), '0001 is abandoned and 0002 belongs to the other series');
    }

    public function testRepunctuatingANumberAbandonsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edited($invoice, $this->input(overrides: ['typedNumber' => '2026-0001']));

        self::assertSame('2026-0001', $this->reloaded($invoice)->number());
        self::assertSame(0, $this->em->getRepository(AbandonedNumber::class)->count());
    }

    public function testATakenNumberIsRefusedOnEdit(): void
    {
        $first = $this->issue->issue($this->input());
        $this->issue->issue($this->input());

        $this->assertRefusedFor('invoice.problem.number_taken', fn () => $this->edited($first, $this->input(overrides: ['typedNumber' => '20260002'])));
    }

    public function testNumberAndSeriesAreLockedOnceDelivered(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->secondSeries('B{NNN}');
        $this->delivered->mark($invoice->id());

        $this->assertRefusedFor('invoice.problem.number_locked', fn () => $this->edited($invoice, $this->input(overrides: ['typedNumber' => '20260050'])));
        $this->assertRefusedFor('invoice.problem.number_locked', fn () => $this->edited($invoice, $this->input(overrides: ['typedNumber' => '2026-0001'])));
        $this->assertRefusedFor('invoice.problem.number_locked', fn () => $this->edited($invoice, $this->input(overrides: ['seriesId' => $other->id()])));
        try {
            $this->reloaded($invoice)->changeNumber($this->catalog->get($other->id()), 'B001');
            self::fail('The aggregate refuses it too.');
        } catch (\DomainException) {
        }

        $this->edited($invoice, $this->input(overrides: ['description' => 'Content stays editable']));
        self::assertSame('Content stays editable', $this->reloaded($invoice)->description());
        self::assertSame('20260001', $this->reloaded($invoice)->number());
    }

    public function testAnEditReturnsTheInvoiceAsSaved(): void
    {
        $id = $this->issue->issue($this->input())->id();

        $saved = $this->edit->edit($id, $this->input([$this->line('9900.00')]), 1);

        self::assertSame(990000, $saved->net()->minor());
        self::assertSame(2, $saved->version());
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($id);
        self::assertSame(990000, $document->net->minor());
        self::assertSame(990000, $document->lines[0]->net()->minor());
    }

    public function testPendingChangesStopAnEditInsteadOfVanishing(): void
    {
        $id = $this->issue->issue($this->input())->id();
        $this->em->clear();
        $this->store->get($id);
        $this->customer();

        $this->expectExceptionMessageIsOrContains('Store pending changes before working on an invoice.');
        $this->edit->edit($id, $this->input(), 1);
    }

    public function testLinesAreMatchedByPositionNotByTheirPlaceInTheList(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two'), $this->line('300.00', text: 'Three')]));
        $connection = $this->em->getConnection();
        $connection->delete('invoice_line', ['invoice_id' => $invoice->id(), 'position' => 1]);

        $this->edited($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('250.00', text: 'New two'), $this->line('350.00', text: 'New three')]));
        $lines = $this->store->lines($this->reloaded($invoice));

        self::assertSame([0, 1, 2], array_map(static fn (InvoiceLine $line): int => $line->position(), $lines));
        self::assertSame(['One', 'New two', 'New three'], array_map(static fn (InvoiceLine $line): string => $line->text(), $lines));

        $connection->delete('invoice_line', ['invoice_id' => $invoice->id(), 'position' => 1]);
        $this->edited($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('250.00', text: 'Two again')]));

        self::assertSame(['One', 'Two again'], array_map(static fn (InvoiceLine $line): string => $line->text(), $this->store->lines($this->reloaded($invoice))));
    }

    public function testATrashedInvoiceCannotBeEdited(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');

        $this->assertRefusedFor('invoice.problem.trashed', fn () => $this->edited($invoice, $this->input(overrides: ['description' => 'x'])));
    }

    public function testTheTaxPointDecidesTheRateOnEditToo(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefusedFor('invoice.problem.line_rate_missing', fn () => $this->edited($invoice, $this->input(overrides: ['taxPointDate' => new \DateTimeImmutable('2023-12-31')])));

        $this->edited($invoice, $this->input([$this->line(kind: VatRateKind::Reduced)], ['taxPointDate' => new \DateTimeImmutable('2024-01-01')]));
        $invoice = $this->reloaded($invoice);

        self::assertSame('2024-01-01', $invoice->taxPointDate()->format('Y-m-d'));
        self::assertSame('12.00', $this->store->lines($invoice)[0]->rate()?->decimal());
    }

    public function testDatesChangeOnEditAndTheNumberStays(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edited($invoice, $this->input(overrides: ['issueDate' => new \DateTimeImmutable('2027-01-05'), 'taxPointDate' => new \DateTimeImmutable('2027-01-06')]));
        $invoice = $this->reloaded($invoice);

        self::assertSame('2027-01-05', $invoice->issueDate()->format('Y-m-d'));
        self::assertSame('2027-01-06', $invoice->taxPointDate()->format('Y-m-d'));
        self::assertSame('20260001', $invoice->number());
        self::assertSame('20260002', $this->issue->issue($this->input())->number(), 'the counter of 2026 did not move');
    }

    public function testARenumberTakesThePeriodOfTheIssueDateGiven(): void
    {
        $other = $this->secondSeries('{YYYY}-{NNN}');
        $invoice = $this->issue->issue($this->input());

        $this->edited($invoice, $this->input(overrides: ['seriesId' => $other->id(), 'issueDate' => new \DateTimeImmutable('2027-01-05')]));

        self::assertSame('2027-001', $this->reloaded($invoice)->number());
    }

    public function testAZeroValueInvoiceIsAllowed(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('0.00')]));

        self::assertSame(0, $invoice->payable()->minor());
        $this->edited($invoice, $this->input([$this->line('0.00', text: 'Still free')]));
        self::assertSame(0, $this->reloaded($invoice)->payable()->minor());
    }

    public function testASaveFromAnOldFormIsRefusedAndChangesNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        self::assertSame(1, $invoice->version());

        $this->edit->edit($invoice->id(), $this->input(overrides: ['description' => 'First']), 1);
        self::assertSame(2, $this->reloaded($invoice)->version());

        try {
            $this->edit->edit($invoice->id(), $this->input(overrides: ['description' => 'Second']), 1);
            self::fail('Expected a refusal.');
        } catch (IssueRefused $refused) {
            self::assertEquals([new IssueProblem('version', 'invoice.problem.changed_meanwhile')], $refused->problems);
        }

        self::assertSame('First', $this->reloaded($invoice)->description());
    }

    public function testAVersionAheadIsRefusedToo(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefusedFor('invoice.problem.changed_meanwhile', fn (): Invoice => $this->edit->edit($invoice->id(), $this->input(), 2));
    }

    public function testEveryKindOfSaveMovesTheVersion(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');
        $trashed = $this->reloaded($invoice)->version();
        $this->trash->restore($invoice->id());
        $restored = $this->reloaded($invoice)->version();
        $this->delivered->mark($invoice->id());
        $delivered = $this->reloaded($invoice)->version();
        $this->delivered->mark($invoice->id());
        $this->trash->restore($invoice->id());

        self::assertSame([2, 3, 4, 4], [$trashed, $restored, $delivered, $this->reloaded($invoice)->version()]);
    }

    public function testARenumberMovesTheVersionOnItsOwnToo(): void
    {
        $invoice = $this->issue->issue($this->input());

        $invoice->changeNumber($invoice->series(), 'NEW-1');

        self::assertSame(2, $invoice->version());
    }

    public function testSparseKeysLoseNoLine(): void
    {
        $invoice = $this->issue->issue($this->input([0 => $this->line('100.00', text: 'One'), 2 => $this->line('200.00', text: 'Two')]));
        $lines = $this->store->lines($this->reloaded($invoice));
        self::assertSame([0, 1], array_map(static fn (InvoiceLine $line): int => $line->position(), $lines));

        $this->edited($invoice, $this->input([0 => $this->line('100.00', text: 'One'), 2 => $this->line('300.00', text: 'Three')]));
        $invoice = $this->reloaded($invoice);

        self::assertSame(['One', 'Three'], array_map(static fn (InvoiceLine $line): string => $line->text(), $this->store->lines($invoice)));
        self::assertSame(40000, $invoice->net()->minor());
    }

    public function testReorderingLinesWorksUnderTheUniquePosition(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('100.00', text: 'One'), $this->line('200.00', text: 'Two'), $this->line('300.00', text: 'Three')]));

        $this->edited($invoice, $this->input([$this->line('300.00', text: 'Three'), $this->line('100.00', text: 'One')]));
        $this->edited($invoice, $this->input([$this->line('100.00', text: 'One'), $this->line('300.00', text: 'Three'), $this->line('200.00', text: 'Two'), $this->line('400.00', text: 'Four')]));

        self::assertSame(['One', 'Three', 'Two', 'Four'], array_map(static fn (InvoiceLine $line): string => $line->text(), $this->store->lines($this->reloaded($invoice))));
    }

    public function testTheDatabaseRefusesTwoLinesAtOnePosition(): void
    {
        $invoice = $this->issue->issue($this->input());
        $connection = $this->em->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM invoice_line WHERE invoice_id = ?', [$invoice->id()]);
        self::assertIsArray($row);
        $row['id'] = '01963c5e-0000-7000-8000-000000000002';

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('invoice_line', $row);
    }

    public function testAnInvoiceOfAnotherTenantCannotBeTouched(): void
    {
        $invoice = $this->issue->issue($this->input());
        $input = $this->input(overrides: ['description' => 'Foreign']);
        $tenants = self::getContainer()->get(TenantContext::class);
        $own = $tenants->current();
        $tenants->switchTo(TenantId::generate());
        $tries = [
            fn (): Invoice => $this->edit->edit($invoice->id(), $input, 1),
            fn (): Invoice => $this->trash->trash($invoice->id(), 'Foreign'),
            fn (): Invoice => $this->trash->restore($invoice->id()),
            fn (): Invoice => $this->delivered->mark($invoice->id()),
        ];
        foreach ($tries as $try) {
            try {
                $try();
                self::fail('Expected the invoice not to exist here.');
            } catch (UnknownInvoice) {
                $this->addToAssertionCount(1);
            }
        }

        $tenants->switchTo($own);
        $invoice = $this->reloaded($invoice);
        self::assertSame('Consulting', $invoice->description());
        self::assertSame(1, $invoice->version());
        self::assertFalse($invoice->isDelivered());
    }

    public function testEditingToAnArchivedContactIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->customer();
        $other->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.customer_archived', fn () => $this->edited($invoice, $this->input(overrides: ['customerId' => $other->id()])));
    }

    public function testEditingToAnArchivedSeriesIsRefusedButItsOwnStaysUsable(): void
    {
        $invoice = $this->issue->issue($this->input());
        $archived = $this->secondSeries('B{NNN}');
        $archived->archive();
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.series_archived', fn () => $this->edited($invoice, $this->input(overrides: ['seriesId' => $archived->id()])));

        $this->catalog->get($this->series->id())->archive();
        $this->em->flush();
        $this->edited($invoice, $this->input(overrides: ['typedNumber' => '20260007']));

        self::assertSame('20260007', $this->reloaded($invoice)->number());
    }

    public function testASeriesOfAnotherFirmOrNoneIsRefusedOnEdit(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefusedFor('invoice.problem.series_unknown', fn () => $this->edited($invoice, $this->input(overrides: ['seriesId' => '01963c5e-0000-7000-8000-000000000000'])));
    }

    public function testChangedFirmRoundingLeavesTheInvoiceAlone(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('10.40')]));
        $settings = self::getContainer()->get(FirmInvoicingSettings::class);
        $row = $settings->for($this->firm);
        $row->change(TotalRounding::None, '', '', '', '', '', '');
        $settings->save($row);
        $this->em->flush();

        $this->edited($invoice, $this->input([$this->line('20.80')]));
        $invoice = $this->reloaded($invoice);

        self::assertSame(TotalRounding::WholeUnit, $invoice->rounding());
        self::assertSame(2517, $invoice->gross()->minor());
        self::assertSame(2500, $invoice->payable()->minor());
    }

    public function testThePayableIsCheckedUnderTheStoredRounding(): void
    {
        $invoice = $this->issue->issue($this->input());
        $settings = self::getContainer()->get(FirmInvoicingSettings::class);
        $row = $settings->for($this->firm);
        $row->change(TotalRounding::None, '', '', '', '', '', '');
        $settings->save($row);
        $this->em->flush();

        $this->edited($invoice, $this->input([$this->line('-0.30')]));

        self::assertSame(0, $this->reloaded($invoice)->payable()->minor());
    }

    public function testATypedNumberWithSpacesAroundItIsTheSameNumber(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->delivered->mark($invoice->id());

        $this->edited($invoice, $this->input(overrides: ['typedNumber' => ' 20260001 ', 'description' => 'Changed']));

        self::assertSame('Changed', $this->reloaded($invoice)->description());
    }

    public function testARenumberIsCheckedAgainstTheSeriesTheInvoiceHas(): void
    {
        $series = $this->secondSeries('B{NNN}');
        $invoice = $this->issue->issue($this->input(overrides: ['seriesId' => $series->id()]));

        $this->assertRefusedFor('invoice.problem.number_out_of_range', fn () => $this->edited($invoice, $this->input(overrides: ['seriesId' => null, 'typedNumber' => 'B2147483648'])));
    }

    public function testASupplyCodeMustExistOnTheTaxPointOnEdit(): void
    {
        $invoice = $this->issue->issue($this->input());
        $input = fn (string $code, string $date): InvoiceInput => $this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: $code)], ['taxPointDate' => new \DateTimeImmutable($date)]);

        $this->assertRefusedFor('invoice.problem.supply_code_invalid', fn () => $this->edited($invoice, $input('21', '2016-09-30')));
        $this->edited($invoice, $input('21', '2016-10-01'));

        self::assertSame('21', $this->store->lines($this->reloaded($invoice))[0]->supplyCode());
    }

    public function testTheRulesUseTheStoredIssuerCountryAndRegime(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->firm->update($this->firm->name(), $this->firm->registrationNumber(), $this->firm->vatId(), new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE'), VatStatus::NotRegistered, 'Register', [self::IBAN]);
        $this->em->flush();

        // Still a Czech payer's document, so a Czech customer's reverse charge needs a code and has its rate.
        $this->assertRefusedFor('invoice.problem.supply_code_invalid', fn () => $this->edited($invoice, $this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null)])));
        $this->edited($invoice, $this->input([$this->line('500.00')]));
        $invoice = $this->reloaded($invoice);

        self::assertSame(10500, $invoice->vat()->minor());
        self::assertSame('CZ', $invoice->issuer()->address->countryCode);
    }

    public function testALineAddedOnEditIsAuditedWithItsValues(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edited($invoice, $this->input([$this->line(), $this->line('50.00', text: 'Added')]));

        $entries = array_values(array_filter($this->entriesOf($invoice), static fn (AuditEntry $entry): bool => InvoiceLine::class === $entry->entityClass() && AuditAction::Created === $entry->action()));
        self::assertCount(2, $entries);
        $changes = $entries[1]->changes();
        self::assertSame([null, 'Added'], $changes['text']);
        self::assertSame([null, 1], $changes['position']);
        self::assertSame([null, 5000], $changes['netMinor']);
        self::assertSame([null, '21.00'], $changes['rate']);
    }

    public function testAFailedEditLeavesEverythingAsItWas(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['typedNumber' => '20260005']));
        $this->issue->issue($this->input(overrides: ['typedNumber' => 'OTHER']));

        $this->refusalOf(fn () => $this->edited($invoice, $this->input([], ['typedNumber' => '20260040'])));

        self::assertSame('20260005', $this->reloaded($invoice)->number());
        self::assertSame('20260006', $this->counter->peek($this->catalog->get($this->series->id()), new \DateTimeImmutable('2026-11-01')));
    }

    public function testReverseChargeOnEditUsesTheCustomerOnTheDocument(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->customer->changeIdentifiers($this->customer->registrationNumber(), null);
        $this->em->flush();

        $this->edited($invoice, $this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')]));

        self::assertSame(TaxTreatment::ReverseCharge, $this->store->lines($this->reloaded($invoice))[0]->treatment());
    }

    private function secondSeries(string $format): Series
    {
        $series = new Series($this->firm, 'Second', SeriesFormat::fromString($format), DocumentKind::Invoice);
        $this->catalog->add($series);
        $this->em->flush();

        return $series;
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
