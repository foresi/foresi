<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Application\VatRules;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\VatDocumentSource;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->reads = new \ArrayObject();
        $this->logged = new \ArrayObject();
    }

    public function testMartinsNovemberIsPayableOnTheTwentyEighthOfDecember(): void
    {
        $this->martin();

        $list = $this->with([
            $this->source('invoicing.issued_vat', [$this->document('invoicing.issued_vat', '2026-11-20', '2026-11-20', 210_000, 0)]),
            $this->source('purchasing.received_vat', [$this->document('purchasing.received_vat', '2026-11-01', '2026-11-24', 0, 21_000), $this->document('purchasing.received_vat', '2026-11-01', '2026-11-05', 0, 50_000)]),
        ])->list('2026-11', '2026-12');

        [$november, $december] = $list->periods;
        self::assertSame(['2026-11', '2026-12-28', 210_000, 21_000, 189_000], [$november->period->key, $november->dueOn->format('Y-m-d'), $november->output->minor(), $november->input->minor(), $november->result->minor()]);
        self::assertCount(2, $november->amounts->documents, 'The document from before the payer day is not counted.');
        self::assertSame('2026-11-12', $november->amounts->period->start->format('Y-m-d'), 'The amounts are those of the clipped period.');
        self::assertSame(0, $december->result->minor());
        self::assertSame([], $list->unavailableSources);
        self::assertSame(['schedules_not_counted', 'plans_not_counted', 'deduction_before_registration'], $list->knownGaps);
    }

    public function testTheSourcesReadTheWholeCalendarPeriods(): void
    {
        $this->stretch(VatStatus::Registered, VatPeriodLength::Quarter, '2026-11-12');

        $this->with([$this->source('invoicing.issued_vat', [])])->list(null, '2026-11');

        self::assertSame([['invoicing.issued_vat', '2026-10-01', '2026-12-31']], $this->reads->getArrayCopy(), 'From the first day of the clipped first quarter to the last day of the quarter that holds November.');
    }

    public function testNothingIsReadWithoutAPeriod(): void
    {
        $this->martin();
        $this->stretch(VatStatus::NotRegistered, null, '2027-01-01');

        $list = $this->with([$this->source('invoicing.issued_vat', [])])->list('2027-02', '2027-03');

        self::assertSame([[], [], []], [$list->periods, $this->reads->getArrayCopy(), $list->unavailableSources]);
    }

    public function testModuleRunsWithoutInvoicingAndPurchasing(): void
    {
        $this->martin();

        $list = $this->with([])->list('2026-11', '2026-11');

        self::assertSame(['invoicing.issued_vat', 'purchasing.received_vat'], $list->unavailableSources);
        self::assertSame(0, $list->periods[0]->result->minor());
    }

    public function testASourceThatFailsIsUnavailableAndTheOthersStillCount(): void
    {
        $this->martin();
        $failing = new class implements VatDocumentSource {
            public function key(): string
            {
                return 'purchasing.received_vat';
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

        self::assertSame(['purchasing.received_vat'], $list->unavailableSources);
        self::assertSame(100, $list->periods[0]->result->minor());
        self::assertSame([['The VAT of the documents of {source} could not be read.', 'purchasing.received_vat']], $this->logged->getArrayCopy());
    }

    public function testWithoutInvoicesReadThePeriodHasTotalsOnly(): void
    {
        $this->martin();
        $received = $this->document('purchasing.received_vat', '2026-11-01', '2026-11-24', 0, 21_000);

        $detail = $this->with([
            $this->source('invoicing.issued_vat', [$this->document('invoicing.issued_vat', '2026-11-20', '2026-11-20', 210_000, 0)], false),
            $this->source('purchasing.received_vat', [$received]),
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

    public function testAGuestIsRefused(): void
    {
        $this->martin();
        $this->signIn($this->guest);

        $this->assertRefused('forbidden', null, fn (): \Foresi\Vat\Application\PeriodDetail => $this->with([])->get('2026-11'));
    }

    /**
     * @param list<VatDocumentSource> $sources
     */
    private function with(array $sources): VatPeriods
    {
        $container = self::getContainer();
        $logger = new class($this->logged) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, array{string, mixed}> $logged
             */
            public function __construct(private \ArrayObject $logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged->append([(string) $message, $context['source'] ?? null]);
            }
        };

        return new VatPeriods($container->get(VatAccess::class), $container->get(VatRules::class), $container->get(FirmVatStatuses::class), $container->get(InstanceClock::class), $sources, $logger);
    }

    /**
     * @param list<DocumentVat> $documents
     */
    private function source(string $key, array $documents, bool $details = true): VatDocumentSource
    {
        return new class($key, $documents, $details, $this->reads) implements VatDocumentSource {
            /**
             * @param list<DocumentVat>                   $documents
             * @param \ArrayObject<int, array{string, string, string}> $reads
             */
            public function __construct(private readonly string $key, private readonly array $documents, private readonly bool $details, private readonly \ArrayObject $reads)
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
