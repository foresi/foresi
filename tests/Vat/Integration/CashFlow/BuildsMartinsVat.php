<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\CashFlow;

use Foresi\CashFlow\Application\RuleMovements;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Tests\Vat\Support\FixedDocumentSource;
use Foresi\Tests\Vat\Support\RecordingPaymentSource;
use Foresi\Vat\Application\PeriodView;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRules;
use Foresi\Vat\Application\VatStore;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\PlannedVatSource;
use Foresi\Vat\Contract\VatPaymentSource;
use Foresi\Vat\Contract\VatSubject;
use Foresi\Vat\Integration\CashFlow\BankVatPayments;
use Psr\Log\NullLogger;

/**
 * Martin's VAT: November 2026 gives 1,890 to pay, due on 28 December, and
 * December 3,000 to get back, expected on 24 February 2027. The bank holds
 * the movements a test pays, read through Cash flow's movement finder.
 */
trait BuildsMartinsVat
{
    private const string OFFICE = '705-77628031/0710';
    private const string SYMBOL = '12345678';

    /** @var list<ActualFlow> */
    private array $movements = [];

    /**
     * Martin's November: an issued invoice of 10,000 + 2,100 on 20 November
     * and a received one of 1,000 + 210 on 24 November; and a December of
     * 3,000 back from a received invoice of 3,000 VAT.
     *
     * @param iterable<VatPaymentSource>|null                                $payments
     * @param iterable<PlannedVatSource>                                     $planned
     * @param \ArrayObject<int, array{string, string, string, ?string}>|null $asked
     */
    protected function vat(?iterable $payments = null, iterable $planned = [], ?\ArrayObject $asked = null): VatPeriods
    {
        $container = self::getContainer();
        $documents = new FixedDocumentSource([$this->document('2026-11-20', 210_000, 0), $this->document('2026-11-24', 0, 21_000), $this->document('2026-12-10', 0, 300_000)]);
        $bank = new BankVatPayments(new RuleMovements([new FakeActualSource('banking.movements', $this->movements)]));
        $payments ??= [$asked instanceof \ArrayObject ? new RecordingPaymentSource($bank, $asked) : $bank];

        return new VatPeriods($container->get(VatAccess::class), $container->get(VatRules::class), $container->get(FirmVatStatuses::class), $container->get(InstanceClock::class), [$documents], new NullLogger(), $container->get(ExchangeRates::class), store: $container->get(VatStore::class), payments: $payments, plannedItems: $planned);
    }

    private function period(string $key): PeriodView
    {
        return $this->vat()->get($key)->period;
    }

    private function paid(string $id, string $on, int $czk, string $symbol = self::SYMBOL): void
    {
        $this->movements[] = new ActualFlow('banking.movements', new FlowSubject('bank_movement', $id), FlowDirection::Out, $this->czk($czk), $this->day($on), counterpartyAccount: self::OFFICE, variableSymbol: $symbol);
    }

    private function document(string $on, int $output, int $input): DocumentVat
    {
        $day = $this->day($on);

        return new DocumentVat('invoicing.issued_vat', new VatSubject('invoice', 'd'.$on, null), 'D'.$on, $day, $day, Money::ofMinor($output, Currency::of('CZK')), Money::ofMinor($input, Currency::of('CZK')));
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }

    private function czk(int $czk): Money
    {
        return Money::ofMinor($czk * 100, Currency::of('CZK'));
    }
}
