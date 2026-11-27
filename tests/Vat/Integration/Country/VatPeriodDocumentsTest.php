<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\Country;

use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Domain\ExchangeRate;
use Foresi\ExchangeRate\Domain\RateNotAvailable;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\PeriodAmounts;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\ScheduleAmount;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Application\VatRules;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\ScheduledVat;
use Foresi\Vat\Contract\VatDocumentSource;
use Foresi\Vat\Contract\VatScheduleSource;
use Foresi\Vat\Contract\VatSubject;
use Psr\Log\AbstractLogger;

/**
 * The amounts of the periods from the document sources: Martin is a monthly
 * payer from 12 November 2026. The sources read the whole calendar periods,
 * a source that is missing or fails is named, and the documents of a source
 * the caller may not read are left out of a period's detail but not out of
 * its totals.
 */
final class VatPeriodDocumentsTest extends VatTestCase
{
    /** @var \ArrayObject<int, array{string, string, string}> */
    private \ArrayObject $reads;

    /** @var \ArrayObject<int, array{string, mixed}> */
    private \ArrayObject $logged;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $asked;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reads = new \ArrayObject();
        $this->logged = new \ArrayObject();
        $this->asked = new \ArrayObject();
    }

    public function testAMonthlyTemplateCountsInEachFuturePeriod(): void
    {
        $this->martin();

        $list = $this->with([], [$this->schedules('invoicing.recurring_vat', [$this->scheduled('2026-12-01', Money::ofMinor(210_000, Currency::CZK)), $this->scheduled('2027-01-01', Money::ofMinor(210_000, Currency::CZK))])])->list('2026-11', '2027-01');

        self::assertSame([['2026-11', 0], ['2026-12', 210_000], ['2027-01', 210_000]], array_map(static fn (\Foresi\Vat\Application\PeriodView $view): array => [$view->period->key, $view->result->minor()], $list->periods));
        self::assertSame([['invoicing.recurring_vat', '2026-11-01', '2027-01-31 2026-11-20 Europe/Prague']], $this->reads->getArrayCopy(), 'The whole periods, with today in the instance\'s zone.');
        self::assertSame([], $this->asked->getArrayCopy(), 'CZK needs no rate.');
        self::assertSame([false, false], [$list->periods[1]->amounts->schedules[0]->fxToday, $list->periods[1]->amounts->schedules[0]->missingCzk]);
        self::assertSame(['invoicing.issued_vat', 'received-documents.received_vat'], $list->unavailableSources);
    }

    public function testAnEurTemplateCountsAtTodaysRate(): void
    {
        $this->martin();
        $eur = $this->scheduled('2026-12-01', Money::ofMinor(21_000, Currency::EUR));

        $list = $this->with([], [$this->schedules('invoicing.recurring_vat', [$eur, $this->scheduled('2027-01-01', Money::ofMinor(42_000, Currency::EUR)), $this->scheduled('2027-01-02', Money::ofMinor(1_000, Currency::USD))])], $this->rates(['EUR' => [1, '25.000'], 'USD' => [100, '2250.5']]))->list('2026-12', '2027-01');

        [$december, $january] = $list->periods;
        self::assertSame([525_000, 'CZK'], [$december->output->minor(), $december->output->currency()->value], '210 EUR at 25.000.');
        self::assertSame([$eur, true, false], [$december->amounts->schedules[0]->schedule, $december->amounts->schedules[0]->fxToday, $december->amounts->schedules[0]->missingCzk]);
        self::assertSame([PeriodAmounts::WARNING_FX_TODAY], $december->warnings);
        self::assertSame(1_050_000 + 22_505, $january->output->minor(), '420 EUR at 25.000 and 10 USD at 2,250.5 per 100.');
        self::assertSame(['EUR 2026-11-20', 'USD 2026-11-20'], $this->asked->getArrayCopy(), 'Each rate is asked once, for today.');
    }

    public function testACurrencyWithoutARateTodayCountsZeroAndWarns(): void
    {
        $this->martin();

        $list = $this->with([], [$this->schedules('invoicing.recurring_vat', [$this->scheduled('2026-12-01', Money::ofMinor(21_000, Currency::EUR)), $this->scheduled('2026-12-02', Money::ofMinor(100, Currency::EUR), 'Support')])])->list('2026-12', '2026-12');

        $december = $list->periods[0];
        self::assertSame([0, 'CZK'], [$december->output->minor(), $december->amounts->schedulesPart->output->currency()->value]);
        self::assertSame([PeriodAmounts::WARNING_MISSING_CZK_VAT, PeriodAmounts::WARNING_FX_TODAY], $december->warnings);
        self::assertSame(['Hosting (2026-12-01)', 'Support (2026-12-02)'], $december->amounts->missingCzk);
        self::assertSame([true, true], [$december->amounts->schedules[0]->fxToday, $december->amounts->schedules[0]->missingCzk]);
        self::assertSame(['EUR 2026-11-20'], $this->asked->getArrayCopy(), 'A missing rate is asked once.');
        self::assertSame([['No rate of {currency} for the VAT of the schedules.', 'EUR']], $this->logged->getArrayCopy());
    }

    public function testAScheduleSourceThatFailsIsUnavailableAndTheOthersStillCount(): void
    {
        $this->martin();
        $failing = new class implements VatScheduleSource {
            public function key(): string
            {
                return 'invoicing.recurring_vat';
            }

            public function mayReadDetails(): bool
            {
                return true;
            }

            public function between(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
            {
                yield from [];

                throw new \RuntimeException('The database is gone.');
            }
        };

        $list = $this->with([$this->source('invoicing.issued_vat', [$this->document('invoicing.issued_vat', '2026-11-20', '2026-11-20', 100, 0)]), $this->source('received-documents.received_vat', [])], [$failing, $this->schedules('other.schedules', [$this->scheduled('2026-11-30', Money::ofMinor(5, Currency::CZK))])])->list('2026-11', '2026-11');

        self::assertSame(['invoicing.recurring_vat'], $list->unavailableSources);
        self::assertSame(105, $list->periods[0]->result->minor());
        self::assertSame([['The VAT of the schedules of {source} could not be read.', 'invoicing.recurring_vat']], $this->logged->getArrayCopy());
    }

    public function testWithoutInvoicesReadThePeriodHasNoSchedulesInItsDetail(): void
    {
        $this->martin();
        $hidden = $this->scheduled('2026-12-01', Money::ofMinor(210_000, Currency::CZK));
        $shown = new ScheduledVat('other.schedules', new VatSubject('plan', 'p', null), 'Other', new \DateTimeImmutable('2026-12-05', new \DateTimeZone('Europe/Prague')), Money::ofMinor(100, Currency::CZK));

        $restricted = $this->with([], [$this->schedules('invoicing.recurring_vat', [$hidden], false), $this->schedules('other.schedules', [$shown])])->get('2026-12');
        $open = $this->with([], [$this->schedules('invoicing.recurring_vat', [$hidden])])->get('2026-12');

        self::assertSame(210_100, $restricted->period->result->minor());
        self::assertSame([$shown], array_map(static fn (ScheduleAmount $amount): ScheduledVat => $amount->schedule, $restricted->schedules));
        self::assertSame(['invoicing.recurring_vat'], $restricted->restrictedSources);
        self::assertSame([$hidden], array_map(static fn (ScheduleAmount $amount): ScheduledVat => $amount->schedule, $open->schedules));
        self::assertSame([], $open->restrictedSources);
    }

    public function testMartinsNovemberIsPayableOnTheTwentyEighthOfDecember(): void
    {
        $this->martin();

        $list = $this->with([
            $this->source('invoicing.issued_vat', [$this->document('invoicing.issued_vat', '2026-11-20', '2026-11-20', 210_000, 0)]),
            $this->source('received-documents.received_vat', [$this->document('received-documents.received_vat', '2026-11-01', '2026-11-24', 0, 21_000), $this->document('received-documents.received_vat', '2026-11-01', '2026-11-05', 0, 50_000)]),
        ])->list('2026-11', '2026-12');

        [$november, $december] = $list->periods;
        self::assertSame(['2026-11', '2026-12-28', 210_000, 21_000, 189_000], [$november->period->key, $november->dueOn->format('Y-m-d'), $november->output->minor(), $november->input->minor(), $november->result->minor()]);
        self::assertCount(2, $november->amounts->documents, 'The document from before the payer day is not counted.');
        self::assertSame('2026-11-12', $november->amounts->period->start->format('Y-m-d'), 'The amounts are those of the clipped period.');
        self::assertSame(0, $december->result->minor());
        self::assertSame([], $list->unavailableSources);
        self::assertSame(['deduction_before_registration'], $list->knownGaps);
    }

    public function testTheSourcesReadTheWholeCalendarPeriods(): void
    {
        $this->stretch(VatStatus::Registered, VatPeriodLength::Quarter, '2026-11-12');

        $this->with([$this->source('invoicing.issued_vat', [])])->list(null, '2026-11');

        self::assertSame([['invoicing.issued_vat', '2026-10-01', '2026-12-31'], ['invoicing.recurring_vat', '2026-10-01', '2026-12-31 2026-11-20 Europe/Prague']], $this->reads->getArrayCopy(), 'From the first day of the clipped first quarter to the last day of the quarter that holds November.');
    }

    public function testModuleRunsWithoutInvoicingAndPurchasing(): void
    {
        $this->martin();

        $list = $this->with([], [])->list('2026-11', '2026-11');

        self::assertSame(['invoicing.issued_vat', 'received-documents.received_vat', 'invoicing.recurring_vat'], $list->unavailableSources);
        self::assertSame(0, $list->periods[0]->result->minor());
    }

    public function testASourceThatFailsIsUnavailableAndTheOthersStillCount(): void
    {
        $this->martin();
        $failing = new class implements VatDocumentSource {
            public function key(): string
            {
                return 'received-documents.received_vat';
            }

            public function mayReadDetails(): bool
            {
                return true;
            }

            public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
            {
                yield from [];

                throw new \RuntimeException('The database is gone.');
            }
        };

        $list = $this->with([$failing, $this->source('invoicing.issued_vat', [$this->document('invoicing.issued_vat', '2026-11-20', '2026-11-20', 100, 0)])])->list('2026-11', '2026-11');

        self::assertSame(['received-documents.received_vat'], $list->unavailableSources);
        self::assertSame(100, $list->periods[0]->result->minor());
        self::assertSame([['The VAT of the documents of {source} could not be read.', 'received-documents.received_vat']], $this->logged->getArrayCopy());
    }

    public function testWithoutInvoicesReadThePeriodHasTotalsOnly(): void
    {
        $this->martin();
        $received = $this->document('received-documents.received_vat', '2026-11-01', '2026-11-24', 0, 21_000);

        $detail = $this->with([
            $this->source('invoicing.issued_vat', [$this->document('invoicing.issued_vat', '2026-11-20', '2026-11-20', 210_000, 0)], false),
            $this->source('received-documents.received_vat', [$received]),
        ])->get(' 2026-11 ');

        self::assertSame(['2026-11', 189_000], [$detail->period->period->key, $detail->period->result->minor()]);
        self::assertSame([$received], $detail->documents);
        self::assertSame(['invoicing.issued_vat'], $detail->restrictedSources);
        self::assertSame(['2026-11'], array_map(static fn (\Foresi\Vat\Application\PeriodView $view): string => $view->period->key, $detail->list->periods));
    }

    public function testAQuarterIsReadByItsKey(): void
    {
        $this->stretch(VatStatus::Registered, VatPeriodLength::Quarter, '2026-11-12');

        $detail = $this->with([$this->source('invoicing.issued_vat', [])])->get('2026-q4');

        self::assertSame(['2026-Q4', '2026-11-12', '2026-12-31'], [$detail->period->period->key, $detail->period->period->start->format('Y-m-d'), $detail->period->period->end->format('Y-m-d')]);
        self::assertSame([], $detail->restrictedSources);
        self::assertSame(['2026-10', '2026-12'], [$detail->list->from, $detail->list->to], 'The whole quarter is asked for.');
    }

    public function testAnAnswerWithNothingToComputeKeepsTheRangeAskedFor(): void
    {
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, null);
        $missing = $this->with([])->list('2026-11', '2027-01');
        $this->firm->update($this->firm->name(), $this->firm->registrationNumber(), $this->firm->vatId(), new Address('Example Street 1', 'Example City', '811 01', 'SK'), $this->firm->vatStatus(), '');
        $this->em->flush();
        $foreign = $this->with([])->list('2026-11', '2027-01');

        self::assertSame([PeriodList::PAYER_DATE_MISSING, '2026-11', '2027-01'], [$missing->reason, $missing->from, $missing->to]);
        self::assertSame([PeriodList::COUNTRY_NOT_SUPPORTED, '2026-11', '2027-01'], [$foreign->reason, $foreign->from, $foreign->to]);
    }

    public function testAPeriodTheFirmDoesNotHaveIsNotFound(): void
    {
        $this->martin();
        $periods = $this->with([]);

        $this->assertRefused('not_found', null, static fn (): \Foresi\Vat\Application\PeriodDetail => $periods->get('2026-10'));
        $this->assertRefused('not_found', null, static fn (): \Foresi\Vat\Application\PeriodDetail => $periods->get('2026-Q4'));
        $this->assertRefused('key_invalid', 'key', static fn (): \Foresi\Vat\Application\PeriodDetail => $periods->get('November'));
        $this->assertRefused('range_too_long', 'key', static fn (): \Foresi\Vat\Application\PeriodDetail => $periods->get('2028-12'));
        self::assertSame('2028-11', $periods->get('2028-11')->period->period->key, 'Twenty-four months ahead is still read.');
    }

    /**
     * @param list<VatDocumentSource>      $sources
     * @param list<VatScheduleSource>|null $schedules an empty recurring source when null
     */
    private function with(array $sources, ?array $schedules = null, ?ExchangeRates $rates = null): VatPeriods
    {
        $container = self::getContainer();
        $logger = new class($this->logged) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, array{string, mixed}> $logged
             */
            public function __construct(private readonly \ArrayObject $logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged->append([(string) $message, $context['source'] ?? $context['currency'] ?? null]);
            }
        };

        return new VatPeriods($container->get(VatAccess::class), $container->get(VatRules::class), $container->get(FirmVatStatuses::class), $container->get(InstanceClock::class), $sources, $logger, $rates ?? $this->rates([]), $schedules ?? [$this->schedules('invoicing.recurring_vat', [])]);
    }

    /**
     * @param list<ScheduledVat> $schedules
     */
    private function schedules(string $key, array $schedules, bool $details = true): VatScheduleSource
    {
        return new readonly class($key, $schedules, $details, $this->reads) implements VatScheduleSource {
            /**
             * @param list<ScheduledVat>                               $schedules
             * @param \ArrayObject<int, array{string, string, string}> $reads
             */
            public function __construct(private string $key, private array $schedules, private bool $details, private \ArrayObject $reads)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function mayReadDetails(): bool
            {
                return $this->details;
            }

            public function between(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
            {
                $this->reads->append([$this->key, $from->format('Y-m-d'), $to->format('Y-m-d').' '.$today->format('Y-m-d e')]);

                return $this->schedules;
            }
        };
    }

    /**
     * Today's rates by currency, as [amount, rate]; a currency not given has none.
     *
     * @param array<string, array{int, string}> $rates
     */
    private function rates(array $rates): ExchangeRates
    {
        return new readonly class($rates, $this->asked) implements ExchangeRates {
            /**
             * @param array<string, array{int, string}> $rates
             * @param \ArrayObject<int, string>         $asked
             */
            public function __construct(private array $rates, private \ArrayObject $asked)
            {
            }

            public function rateOn(string $currency, \DateTimeImmutable $date): ExchangeRate
            {
                $this->asked->append($currency.' '.$date->format('Y-m-d'));
                if (!isset($this->rates[$currency])) {
                    throw RateNotAvailable::for($currency, $date);
                }

                return new ExchangeRate($currency, $this->rates[$currency][0], $this->rates[$currency][1], $date, $date);
            }
        };
    }

    private function scheduled(string $taxPoint, Money $output, string $label = 'Hosting'): ScheduledVat
    {
        return new ScheduledVat('invoicing.recurring_vat', new VatSubject('recurring_invoice', 't-'.$taxPoint, null), $label.' ('.$taxPoint.')', new \DateTimeImmutable($taxPoint, new \DateTimeZone('Europe/Prague')), $output);
    }

    /**
     * @param list<DocumentVat> $documents
     */
    private function source(string $key, array $documents, bool $details = true): VatDocumentSource
    {
        return new readonly class($key, $documents, $details, $this->reads) implements VatDocumentSource {
            /**
             * @param list<DocumentVat>                                $documents
             * @param \ArrayObject<int, array{string, string, string}> $reads
             */
            public function __construct(private string $key, private array $documents, private bool $details, private \ArrayObject $reads)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function mayReadDetails(): bool
            {
                return $this->details;
            }

            public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
            {
                $this->reads->append([$this->key, $from->format('Y-m-d'), $to->format('Y-m-d')]);

                return $this->documents;
            }
        };
    }

    private function document(string $source, string $countsOn, string $taxPoint, int $output, int $input): DocumentVat
    {
        $zone = new \DateTimeZone('Europe/Prague');

        return new DocumentVat($source, new VatSubject('document', $source.$taxPoint, null), $taxPoint, new \DateTimeImmutable($countsOn, $zone), new \DateTimeImmutable($taxPoint, $zone), Money::ofMinor($output, Currency::CZK), Money::ofMinor($input, Currency::CZK));
    }

    /**
     * Martin: not a payer from the start, a monthly payer from 12 November 2026.
     */
    private function martin(): void
    {
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-11-12');
    }

    private function assertRefused(string $reason, ?string $field, \Closure $work): void
    {
        try {
            $work();
            self::fail('Expected a refusal '.$reason.'.');
        } catch (VatRefused $refused) {
            self::assertSame([$reason, $field], [$refused->reason(), $refused->field()]);
        }
    }
}
