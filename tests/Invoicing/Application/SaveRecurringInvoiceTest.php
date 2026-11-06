<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RecurringHistory;
use App\Invoicing\Application\RecurringRefused;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringState;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Id;

final class SaveRecurringInvoiceTest extends RecurringTestCase
{
    public function testCreateStoresTemplateAndLines(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'Hosting {month/year}'), $this->line('250.00', text: 'Support')]));

        $row = $this->templateRow($template->id());
        self::assertSame('2026-12-01', $row['next_occurrence']);
        self::assertSame('2026-12-01', $row['first_issue_on']);
        self::assertSame('month', $row['recurrence_unit']);
        self::assertSame(1, $row['recurrence_every']);
        self::assertSame('never', $row['recurrence_end']);
        self::assertSame('issue_date', $row['tax_point']);
        self::assertSame(14, $row['due_days']);
        self::assertSame('send', $row['delivery']);
        self::assertSame('active', $row['state']);
        self::assertSame(0, $row['issued_count']);
        self::assertSame($this->firm->id(), $row['legal_entity_id']);
        self::assertSame($this->series->id(), $row['series_id']);
        self::assertSame($this->customer->id(), $row['customer_id']);
        self::assertSame('PO-{month}', $row['order_number']);
        self::assertSame('Hosting {month/year}', $row['description']);
        self::assertSame('CZK', $row['currency']);
        self::assertSame(self::IBAN, $row['bank_account']);
        self::assertNull($row['owner_id'], 'nobody is signed in here');
        self::assertNull($row['imported_from']);

        $lines = $this->lineRows($template->id());
        self::assertCount(2, $lines);
        self::assertSame('Hosting {month/year}', $lines[0]['text']);
        self::assertSame(100000, $lines[0]['price_minor']);
        self::assertSame('base', $lines[0]['rate_kind']);
        self::assertSame('Support', $lines[1]['text']);
        self::assertSame(25000, $lines[1]['price_minor']);
        self::assertSame(1, $lines[1]['position']);

        $stored = $this->templates->get($template->id());
        self::assertEquals(new \DateTimeImmutable('2026-12-01'), $stored->nextOccurrence());
        self::assertSame(RecurringState::Active, $stored->state());
        self::assertCount(2, $this->templates->lines($stored));
    }

    public function testFirstIssueInThePastIsRefused(): void
    {
        self::assertSame(['recurring.problem.first_issue_past'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-11-01')]))));

        $today = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-11-02')]));
        self::assertSame('2026-11-02', $this->templateRow($today->id())['next_occurrence']);
    }

    public function testAnArchivedCustomerForTheFirmIsRefused(): void
    {
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->customer, $this->firm, true);
        $this->em->flush();

        self::assertSame(['invoice.problem.customer_archived'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput())));
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM recurring_invoice'));
    }

    public function testAnEmptyLineListIsRefused(): void
    {
        self::assertSame(['invoice.problem.no_lines'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput([]))));
    }

    public function testTheLineAndFirmChecksOfAnInvoiceApply(): void
    {
        $this->firm->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        self::assertSame(
            ['invoice.problem.firm_archived', 'invoice.problem.line_text_missing', 'invoice.problem.vat_invalid'],
            $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput([$this->line(text: ' '), $this->line(treatment: TaxTreatment::NotApplicable, kind: null)]))),
        );
    }

    public function testTheScheduleFieldsAreChecked(): void
    {
        self::assertSame(['recurring.problem.due_days_invalid'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['dueDays' => 366]))));
        self::assertSame(['recurring.problem.due_days_invalid'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['dueDays' => -1]))));
        self::assertSame(['invoice.problem.bank_account_foreign'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['bankAccount' => 'DE89370400440532013000']))));
    }

    public function testEditWithAStaleVersionIsRefused(): void
    {
        $template = $this->save->create($this->recurringInput());
        $version = $template->version();
        $this->em->clear();
        $this->save->edit($template->id(), $version, $this->recurringInput(overrides: ['description' => 'First edit']));
        $this->em->clear();

        self::assertSame(['invoice.problem.changed_meanwhile'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->edit($template->id(), $version, $this->recurringInput(overrides: ['description' => 'Second edit']))));
        self::assertSame('First edit', $this->templateRow($template->id())['description']);
    }

    public function testEditKeepsIssuedCountAndRecomputesNext(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'One'), $this->line(text: 'Two')]));
        $this->issueOnce($template->id(), '2026-12-01');
        self::mockTime('2026-12-05 11:00:00');

        $this->em->clear();
        $edited = $this->save->edit($template->id(), $this->templates->get($template->id())->version(), $this->recurringInput(
            [$this->line(text: 'Only one')],
            ['recurrence' => $this->monthly('2026-12-15'), 'description' => 'Edited'],
        ));

        $row = $this->templateRow($edited->id());
        self::assertSame(1, $row['issued_count']);
        self::assertSame('2026-12-15', $row['next_occurrence']);
        self::assertSame('2026-12-01', $row['last_occurrence']);
        self::assertSame('Edited', $row['description']);
        $lines = $this->lineRows($edited->id());
        self::assertCount(1, $lines);
        self::assertSame('Only one', $lines[0]['text']);
    }

    public function testAnEditKeepsAnUnchangedFirstIssueDateInThePastAndAPendingOccurrence(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->issueOnce($template->id(), '2026-12-01');
        self::mockTime('2027-01-15 11:00:00');

        $this->em->clear();
        $this->save->edit($template->id(), $this->templates->get($template->id())->version(), $this->recurringInput(overrides: ['description' => 'Edited']));

        $row = $this->templateRow($template->id());
        self::assertSame('2026-12-01', $row['first_issue_on']);
        self::assertSame('2027-01-01', $row['next_occurrence'], 'the run that has not caught up yet still issues January');
        self::assertSame('Edited', $row['description']);
    }

    public function testAChangedFirstIssueDateInThePastIsRefusedOnAnEdit(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->em->clear();

        self::assertSame(['recurring.problem.first_issue_past'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->edit($template->id(), $template->version(), $this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-10-01')]))));
    }

    public function testAnEditThatLeavesNoOccurrenceIsRefused(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->issueOnce($template->id(), '2026-12-01');

        $this->em->clear();
        self::assertSame(['recurring.problem.no_occurrence_left'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->edit($template->id(), $this->templates->get($template->id())->version(), $this->recurringInput(overrides: ['recurrence' => $this->monthly(end: RecurrenceEnd::AfterCount, endAfter: 1)]))));
        self::assertSame('2027-01-01', $this->templateRow($template->id())['next_occurrence']);
    }

    public function testARefusalNamesEachProblemByItsField(): void
    {
        try {
            $this->save->create($this->recurringInput([$this->line(), $this->line(treatment: TaxTreatment::NotApplicable, kind: null)], ['orderNumber' => str_repeat('a', Invoice::MAX_ORDER_NUMBER_LENGTH + 1), 'constantSymbol' => 'ABC']));
            self::fail('Expected the template to be refused.');
        } catch (RecurringRefused $refused) {
            self::assertSame(
                [['orderNumber', 'invoice.problem.too_long'], ['constantSymbol', 'invoice.problem.constant_symbol_invalid'], ['lines.1.vat', 'invoice.problem.vat_invalid']],
                array_map(static fn (IssueProblem $problem): array => [$problem->field, $problem->key], $refused->problems),
            );
            self::assertSame('The recurring invoice cannot be changed: invoice.problem.too_long, invoice.problem.constant_symbol_invalid, invoice.problem.vat_invalid', $refused->getMessage());
        }
    }

    public function testTheDocumentFieldsTakeTheirWholeLengthInAnyScript(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['orderNumber' => str_repeat('č', Invoice::MAX_ORDER_NUMBER_LENGTH), 'constantSymbol' => str_repeat('9', Invoice::MAX_CONSTANT_SYMBOL_LENGTH), 'dueDays' => RecurringInvoice::MAX_DUE_DAYS]));

        $row = $this->templateRow($template->id());
        self::assertSame([str_repeat('č', Invoice::MAX_ORDER_NUMBER_LENGTH), '9999999999', 365], [$row['order_number'], $row['constant_symbol'], $row['due_days']]);
    }

    public function testAConstantSymbolIsDigitsOnly(): void
    {
        self::assertSame(['invoice.problem.constant_symbol_invalid'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['constantSymbol' => str_repeat('č', Invoice::MAX_CONSTANT_SYMBOL_LENGTH)]))));
        self::assertSame(['invoice.problem.too_long'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['constantSymbol' => str_repeat('9', Invoice::MAX_CONSTANT_SYMBOL_LENGTH + 1)]))));
    }

    public function testAnOrderNumberThePdfCannotPrintIsRefused(): void
    {
        self::assertSame(['invoice.problem.unprintable'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['orderNumber' => 'PO 中文']))));
    }

    public function testAnArchivedSeriesIsRefused(): void
    {
        $this->series->archive();
        $this->em->flush();

        self::assertSame(['invoice.problem.series_archived'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput())));
    }

    public function testASeriesOfAnotherKindIsRefused(): void
    {
        $taxDocuments = new Series($this->firm, 'Tax documents', SeriesFormat::fromString('DD{YYYY}{NNNN}'), DocumentKind::PaymentTaxDocument);
        $this->em->persist($taxDocuments);
        $this->em->flush();

        self::assertSame(['invoice.problem.series_kind'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['seriesId' => $taxDocuments->id()]))));
    }

    public function testASeriesOfCorrectionsIsRefused(): void
    {
        $corrections = new Series($this->firm, 'Corrections', SeriesFormat::fromString('OD{YYYY}{NNNN}'), DocumentKind::CorrectionDocument);
        $this->em->persist($corrections);
        $this->em->flush();

        self::assertSame(['invoice.problem.series_kind'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['seriesId' => $corrections->id()]))));
    }

    public function testAnAdvanceSeriesIsAccepted(): void
    {
        $advances = new Series($this->firm, 'Advances', SeriesFormat::fromString('ZF{YYYY}{NNNN}'), DocumentKind::Advance);
        $this->em->persist($advances);
        $this->em->flush();

        $template = $this->save->create($this->recurringInput(overrides: ['seriesId' => $advances->id()]));

        self::assertSame($advances->id(), $template->series()?->id());
    }

    public function testAnUnknownCustomerIsRefused(): void
    {
        self::assertSame(['invoice.problem.customer_unknown'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput(overrides: ['customerId' => Id::generate()]))));
    }

    public function testTheAmountsAreCheckedOnTheFirstTaxPoint(): void
    {
        self::assertSame(['invoice.problem.payable_negative'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->create($this->recurringInput([$this->line('-100.00')]))));
    }

    public function testABankAccountIsKeptForABankTransferOnly(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['paymentMethod' => PaymentMethod::Cash]));

        self::assertNull($this->templateRow($template->id())['bank_account']);
    }

    public function testAnInputCopiesToBothByDefaultAndListsItsLines(): void
    {
        $input = $this->recurringInput([3 => $this->line(text: 'First'), 7 => $this->line(text: 'Second')]);

        self::assertTrue($input->copyToMe);
        self::assertTrue($input->copyToOthers);
        self::assertSame(['First', 'Second'], array_map(static fn (LineInput $line): string => $line->text, $input->lines));
        self::assertSame([0, 1], array_keys($input->lines));
    }

    public function testStartPausedStoresPausedAndImportedFrom(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly(unit: RecurrenceUnit::Year)]), startPaused: true, importedFrom: 'example-template-42');

        $row = $this->templateRow($template->id());
        self::assertSame('paused', $row['state']);
        self::assertNotNull($row['paused_at']);
        self::assertSame('example-template-42', $row['imported_from']);
        self::assertSame('year', $row['recurrence_unit']);
        self::assertSame('2026-12-01', $row['next_occurrence']);
    }

    public function testAnImportTakesAPastFirstIssueAndGoesOnAfterWhatItIssued(): void
    {
        $history = new RecurringHistory('example-template-7', 10, new \DateTimeImmutable('2026-10-15'));
        $input = $this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-01-15', RecurrenceEnd::AfterCount, endAfter: 24)]);

        $template = $this->save->import($input, $history);

        $row = $this->templateRow($template->id());
        self::assertSame('paused', $row['state']);
        self::assertSame('example-template-7', $row['imported_from']);
        self::assertSame('2026-01-15', $row['first_issue_on']);
        self::assertSame(10, $row['issued_count']);
        self::assertSame('2026-10-15', $row['last_occurrence']);
        self::assertSame('2026-11-15', $row['next_occurrence']);
        self::assertSame(24, $row['end_after']);
        self::assertCount(1, $this->lineRows($template->id()));
    }

    public function testAnImportWithoutIssuesStartsOnItsFirstDateEvenWhenPassed(): void
    {
        $template = $this->save->import($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-10-31')]), new RecurringHistory('example-template-8', 0, null));

        self::assertSame('2026-10-31', $this->templateRow($template->id())['next_occurrence']);
    }

    public function testAnImportWhoseEndRuleAllowsNoMoreIsStoredEnded(): void
    {
        $input = $this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-01-15', RecurrenceEnd::AfterCount, endAfter: 10)]);

        $template = $this->save->import($input, new RecurringHistory('example-template-9', 10, new \DateTimeImmutable('2026-10-15')));

        $row = $this->templateRow($template->id());
        self::assertSame('ended', $row['state']);
        self::assertNull($row['next_occurrence']);
        self::assertNull($row['paused_at']);
        self::assertIsString($row['ended_at']);
        self::assertStringStartsWith('2026-10-15', $row['ended_at']);
    }

    public function testAnImportMeetsTheOtherRules(): void
    {
        $input = $this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-01-15'), 'dueDays' => RecurringInvoice::MAX_DUE_DAYS + 1]);

        self::assertSame(['recurring.problem.due_days_invalid'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->import($input, new RecurringHistory('example-template-10', 2, new \DateTimeImmutable('2026-02-15')))));
    }

    public function testOnlyAnImportedTemplateThatIssuedNothingCarriesOver(): void
    {
        $own = $this->save->create($this->recurringInput());
        try {
            $own->carryOver(1, new \DateTimeImmutable('2026-11-01'));
            self::fail('A template of its own carries nothing over.');
        } catch (\DomainException $refused) {
            self::assertSame('Only an imported template that issued nothing here carries over what it issued.', $refused->getMessage());
        }

        $imported = $this->save->import($this->recurringInput(), new RecurringHistory('example-template-11', 1, new \DateTimeImmutable('2026-11-01')));
        $this->expectException(\DomainException::class);
        $imported->carryOver(1, null);
    }

    public function testAnImportedTemplateCarriesOverNoNegativeCount(): void
    {
        $imported = $this->save->create($this->recurringInput(), startPaused: true, importedFrom: 'example-template-12');

        $this->expectExceptionMessageIsOrContains('An imported template issued no invoices or more.');
        $imported->carryOver(-1, null);
    }

    private function issueOnce(string $id, string $occurrence): void
    {
        self::getContainer()->get(TransactionalWork::class)->run(function () use ($id, $occurrence): void {
            $this->templates->lock($id)->advance(new \DateTimeImmutable($occurrence));
        });
    }
}
