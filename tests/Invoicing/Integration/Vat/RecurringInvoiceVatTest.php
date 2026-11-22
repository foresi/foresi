<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Vat;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\IssueRecurringOccurrence;
use Foresi\Invoicing\Application\RecurringAmounts;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringOverview;
use Foresi\Invoicing\Application\RecurringPage;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Invoicing\Integration\Vat\IssuedDocumentVat;
use Foresi\Invoicing\Integration\Vat\RecurringInvoiceVat;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\Invoicing\Application\RecurringTestCase;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\ScheduledVat;

/**
 * The output VAT the recurring invoices will give the VAT module, on 2
 * November 2026: each occurrence of an active template not issued yet, on
 * the tax point its invoice would get, in the template's currency (the
 * home currency, as an invoice's lines are). An issued occurrence is an invoice and counts as a document only.
 */
final class RecurringInvoiceVatTest extends RecurringTestCase
{
    public function testAMonthlyTemplateCountsInEachFuturePeriod(): void
    {
        // 10,000 at 21 % from 1 December 2026.
        $template = $this->save->create($this->recurringInput([$this->line('10000.00')], ['name' => 'Hosting']));

        $vat = $this->between('2026-11-01', '2027-02-28');

        self::assertSame([
            ['2026-12-01', 210_000, 'CZK'],
            ['2027-01-01', 210_000, 'CZK'],
            ['2027-02-01', 210_000, 'CZK'],
        ], $this->days($vat));
        self::assertSame([RecurringInvoiceVat::KEY, 'recurring_invoice', $template->id(), null, 'Hosting (2026-12-01)'], [$vat[0]->source, $vat[0]->subject->type, $vat[0]->subject->id, $vat[0]->subject->url, $vat[0]->label]);
        self::assertSame('Europe/Prague', $vat[0]->taxPointOn->getTimezone()->getName());
    }

    public function testOnlyTheTaxPointsOfTheDaysAskedCount(): void
    {
        $this->save->create($this->recurringInput([$this->line('10000.00')]));

        self::assertSame([['2027-01-01', 210_000, 'CZK']], $this->days($this->between('2027-01-01', '2027-01-31')));
        self::assertSame(['2026-12-01', '2027-01-01'], array_column($this->days($this->between('2026-12-01', '2027-01-01')), 0), 'Both ends are included.');
        self::assertSame([], $this->between('2026-12-02', '2026-12-31'));
    }

    public function testTheTaxPointIsTheOneTheInvoiceWouldGet(): void
    {
        $this->save->create($this->recurringInput([$this->line('10000.00')], ['taxPoint' => TaxPointRule::LastDayOfMonth]));

        self::assertSame(['2026-12-31', '2027-01-31'], array_column($this->days($this->between('2026-12-01', '2027-01-31')), 0));
        self::assertSame([], $this->between('2026-12-01', '2026-12-30'), 'The occurrence of 1 December has its tax point on the 31st.');
    }

    public function testAnIssuedOccurrenceCountsOnceAsADocument(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line('10000.00')]));
        self::mockTime('2026-12-01 09:00:00');
        $invoice = self::getContainer()->get(IssueRecurringOccurrence::class)->issue($template->id(), new \DateTimeImmutable('2026-12-01'));
        self::assertNotNull($invoice);

        $schedules = $this->between('2026-12-01', '2027-01-31');
        $documents = iterator_to_array(self::getContainer()->get(IssuedDocumentVat::class)->between($this->day('2026-12-01'), $this->day('2027-01-31')), false);

        self::assertSame(['2027-01-01'], array_column($this->days($schedules), 0), 'The December occurrence is no longer a schedule.');
        self::assertSame([[$invoice->id(), '2026-12-01', 210_000]], array_map(static fn (DocumentVat $document): array => [$document->subject->id, $document->taxPointOn->format('Y-m-d'), $document->output->minor()], $documents));
    }

    public function testAnEndedOrPausedTemplateAddsNothing(): void
    {
        $ended = $this->save->create($this->recurringInput([$this->line('10000.00')]));
        $paused = $this->save->create($this->recurringInput([$this->line('20000.00')]));
        $this->lifecycle->end($ended->id());
        $this->lifecycle->pause($paused->id());

        self::assertSame([], $this->between('2026-11-01', '2027-02-28'));
    }

    public function testATemplateStopsAtItsLastOccurrence(): void
    {
        $this->save->create($this->recurringInput([$this->line('10000.00')], ['recurrence' => $this->monthly('2026-12-01', RecurrenceEnd::AfterCount, endAfter: 2)]));

        self::assertSame(['2026-12-01', '2027-01-01'], array_column($this->days($this->between('2026-11-01', '2027-06-30')), 0));
    }

    public function testADailyTemplateCountsEveryDayBeyondOneReadOfItsDates(): void
    {
        $this->save->create($this->recurringInput([$this->line('100.00')], ['recurrence' => $this->monthly('2026-11-03', unit: RecurrenceUnit::Day)]));

        $days = array_column($this->days($this->between('2026-11-01', '2027-01-31')), 0);

        self::assertCount(90, $days, 'From 3 November 2026 to 31 January 2027.');
        self::assertSame(['2026-11-03', '2027-01-31'], [$days[0], $days[89]]);
    }

    public function testAReverseChargeTemplateAddsNoOutput(): void
    {
        $this->save->create($this->recurringInput([$this->line('10000.00', treatment: TaxTreatment::ReverseCharge, kind: null)]));

        self::assertSame([], $this->between('2026-11-01', '2027-02-28'));
    }

    public function testEveryPageOfTheActiveTemplatesIsRead(): void
    {
        $this->save->create($this->recurringInput([$this->line('100.00')], ['name' => 'First']));
        $this->save->create($this->recurringInput([$this->line('100.00')], ['name' => 'Second']));
        $this->save->create($this->recurringInput([$this->line('100.00')], ['name' => 'Third']));
        $container = self::getContainer();
        $all = $container->get(RecurringInvoices::class)->page(new RecurringFilter(RecurringState::Active, false, 1))->templates;
        self::assertCount(3, $all);
        $templates = self::createStub(RecurringInvoices::class);
        $templates->method('page')->willReturnCallback(static fn (RecurringFilter $filter): RecurringPage => new RecurringPage([$all[$filter->page - 1]], \count($all), $filter->page, \count($all)));
        $source = new RecurringInvoiceVat($templates, $container->get(RecurringOverview::class), $container->get(RecurringAmounts::class), $container->get(ApiTokenContext::class));

        $labels = array_map(static fn (ScheduledVat $vat): string => $vat->label, iterator_to_array($source->between($this->day('2026-12-01'), $this->day('2026-12-31'), $this->day('2026-11-02')), false));

        sort($labels);
        self::assertSame(['First (2026-12-01)', 'Second (2026-12-01)', 'Third (2026-12-01)'], $labels);
    }

    public function testTheCallerMayReadTheDetailsWithoutATokenOrWithTheReadScopeOfInvoices(): void
    {
        $container = self::getContainer();
        $with = fn (string $scope): RecurringInvoiceVat => new RecurringInvoiceVat($container->get(RecurringInvoices::class), $container->get(RecurringOverview::class), $container->get(RecurringAmounts::class), $this->grant($scope));

        self::assertTrue($container->get(RecurringInvoiceVat::class)->mayReadDetails());
        self::assertTrue($with('invoices:read')->mayReadDetails());
        self::assertFalse($with('vat:read')->mayReadDetails());
        self::assertSame('invoicing.recurring_vat', $container->get(RecurringInvoiceVat::class)->key());
    }

    /**
     * @param list<ScheduledVat> $vat
     *
     * @return list<array{string, int, string}>
     */
    private function days(array $vat): array
    {
        return array_map(static fn (ScheduledVat $one): array => [$one->taxPointOn->format('Y-m-d'), $one->output->minor(), $one->output->currency()->value], $vat);
    }

    /**
     * @return list<ScheduledVat>
     */
    private function between(string $from, string $to): array
    {
        $this->em->clear();

        return iterator_to_array(self::getContainer()->get(RecurringInvoiceVat::class)->between($this->day($from), $this->day($to), $this->day('2026-11-02')), false);
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }

    private function grant(string $scope): ApiTokenContext
    {
        return new readonly class(new ApiTokenGrant('019a0000-0000-7000-8000-000000000001', '019a0000-0000-7000-8000-0000000000aa', 'Reporting', [ApiScope::from($scope)], new \DateTimeImmutable('2026-12-01', new \DateTimeZone('UTC')))) implements ApiTokenContext {
            public function __construct(private ApiTokenGrant $grant)
            {
            }

            public function grant(): ApiTokenGrant
            {
                return $this->grant;
            }

            public function requireScope(ApiScope $scope): ApiTokenGrant
            {
                return $this->grant;
            }
        };
    }
}
