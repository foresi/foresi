<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Application;

use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Domain\ExchangeRate;
use Foresi\ExchangeRate\Domain\RateNotAvailable;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Application\VatRules;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\ScheduledVat;
use Foresi\Vat\Contract\VatDocumentSource;
use Foresi\Vat\Contract\VatScheduleSource;
use Psr\Log\AbstractLogger;

/**
 * The amounts of the periods from the document sources, whatever the
 * country's rules: nothing is read without a period, and a guest is refused.
 * The periods of the Czech rules with their amounts are in
 * Integration/Country.
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

    public function testNothingIsReadWithoutAPeriod(): void
    {
        $this->martin();
        $this->stretch(VatStatus::NotRegistered, null, '2027-01-01');

        $list = $this->with([$this->source('invoicing.issued_vat', [])])->list('2027-02', '2027-03');

        self::assertSame([[], [], []], [$list->periods, $this->reads->getArrayCopy(), $list->unavailableSources]);
    }

    public function testAGuestIsRefused(): void
    {
        $this->martin();
        $this->signIn($this->guest);

        $this->assertRefused('forbidden', null, fn (): \Foresi\Vat\Application\PeriodDetail => $this->with([])->get('2026-11'));
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
