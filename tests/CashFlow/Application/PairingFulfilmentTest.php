<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PairingCandidate;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Infrastructure\Source\PlannedItemFlows;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Psr\Log\NullLogger;

/**
 * An occurrence with an auto or confirmed pairing is fulfilled: the forecast
 * leaves it out, though its item has no counterparty and no match rule. A
 * proposed or a declined pairing fulfils nothing. Today is 17 November 2026.
 */
final class PairingFulfilmentTest extends CashFlowTestCase
{
    public function testAPairedOccurrenceIsFulfilledInTheForecast(): void
    {
        $rent = $this->planned(on: '2026-11-25', repeat: 'month');
        $other = $this->planned(on: '2026-11-26', repeat: 'month', name: 'Other');
        $confirmed = $this->planned(on: '2026-11-27', repeat: 'month', name: 'Confirmed');
        $this->pair($rent, '2026-11-25', 'auto');
        $this->pair($confirmed, '2026-11-27', 'confirmed');

        $forecast = $this->forecast();

        self::assertSame('2026-12-25', $this->days($forecast, $rent)[0], 'November is paired; December is not.');
        self::assertSame('2026-11-26', $this->days($forecast, $other)[0]);
        self::assertSame('2026-12-27', $this->days($forecast, $confirmed)[0]);
    }

    public function testAProposedOrDeclinedPairingDoesNotFulfil(): void
    {
        $rent = $this->planned(on: '2026-11-25', repeat: 'month');
        $fee = $this->planned(on: '2026-11-26', repeat: 'month', name: 'Fee');
        $this->pair($rent, '2026-11-25', 'proposed');
        $this->pair($fee, '2026-11-26', 'declined');

        $forecast = $this->forecast();

        self::assertSame('2026-11-25', $this->days($forecast, $rent)[0]);
        self::assertSame('2026-11-26', $this->days($forecast, $fee)[0]);
    }

    public function testAPairingOfAnotherOccurrenceFulfilsNothingHere(): void
    {
        $rent = $this->planned(on: '2026-11-25', repeat: 'month');
        $this->pair($rent, '2026-10-25', 'auto');

        self::assertSame('2026-11-25', $this->days($this->forecast(), $rent)[0]);
    }

    /**
     * @param list<ActualFlowSource> $actuals
     */
    private function forecast(array $actuals = []): Forecast
    {
        $container = self::getContainer();
        $sources = [new PlannedItemFlows($container->get(CashFlowStore::class), $container->get(PlannedItemVatRule::class))];

        return new Forecasts($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), new NullLogger(), $sources, [], $actuals)->of();
    }

    private function pair(PlannedItem $item, string $on, string $state): void
    {
        $now = new \DateTimeImmutable('2026-11-17 10:00:00');
        $day = new \DateTimeImmutable($on);
        $subject = 'doc-'.$item->id().'-'.$on;
        $row = match ($state) {
            'auto' => DocumentPairing::auto('received_document', $subject, $this->customer, $item, $day, $now),
            'confirmed' => $this->confirmed($subject, $item, $day, $now),
            'proposed' => DocumentPairing::propose('received_document', $subject, $this->customer, [new PairingCandidate($item->id(), $day)], $item, $now),
            default => $this->declined($subject, $item, $day, $now),
        };
        $this->em->persist($row);
        $this->em->flush();
    }

    private function confirmed(string $subject, PlannedItem $item, \DateTimeImmutable $day, \DateTimeImmutable $now): DocumentPairing
    {
        $row = DocumentPairing::propose('received_document', $subject, $this->customer, [new PairingCandidate($item->id(), $day)], $item, $now);
        $row->confirm($item, $day, $this->owner->user()->id(), $now);

        return $row;
    }

    private function declined(string $subject, PlannedItem $item, \DateTimeImmutable $day, \DateTimeImmutable $now): DocumentPairing
    {
        $row = DocumentPairing::propose('received_document', $subject, $this->customer, [new PairingCandidate($item->id(), $day)], $item, $now);
        $row->decline($this->owner->user()->id(), $now);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function days(Forecast $forecast, PlannedItem $item): array
    {
        $days = [];
        foreach ($forecast->periods as $period) {
            foreach ($period->lines as $line) {
                if ($line->flow->subject->id === $item->id()) {
                    $days[] = $line->countedOn->format('Y-m-d');
                }
            }
        }

        return $days;
    }
}
