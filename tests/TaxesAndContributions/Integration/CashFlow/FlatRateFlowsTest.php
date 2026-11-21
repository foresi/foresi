<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Contract\ReadFlow;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\DuePayments;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\SetAuthorityAccountInput;
use Foresi\TaxesAndContributions\Application\TaxesAccess;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Integration\CashFlow\BankLevyPayments;
use Foresi\TaxesAndContributions\Integration\CashFlow\EstimateFlows;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The flat-rate tax in the cash flow (spec phase 1, review focus 4): its
 * advances are tax flows due by the 20th, with no ordinary advance beside
 * them, paid by a bank movement to the tax office's flat-rate account, and a
 * member sees them as category totals only. The profile events of the regime
 * say what changed, never a band amount.
 */
final class FlatRateFlowsTest extends TaxesTestCase
{
    private const string ACCOUNT = '7720-77628031/0710';
    private const string SYMBOL = '7805121234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $this->writes()->addStatus(new AddStatusInput('flat_rate_band', '1', '2026-01-01'));
        $this->writes()->setAuthority(new SetAuthorityAccountInput('flat_rate', self::ACCOUNT, self::SYMBOL));
    }

    public function testFlatRateAdvancesAreTaxFlowsOnThe20thWithNoOrdinaryAdvances(): void
    {
        $flows = $this->flows();

        $december = $flows['flat_rate.advance.2026-12'];
        self::assertSame([FlowCategory::Taxes, FlowDirection::Out, '2026-12-21', 916_200], [$december->category, $december->direction, $december->expectedOn->format('Y-m-d'), $december->amount->minor()]);
        self::assertSame('Paušální daň (finanční úřad) – záloha 12/2026', $december->label);
        self::assertSame([], array_filter(array_keys($flows), static fn (string $key): bool => !str_starts_with($key, 'flat_rate.advance.')), 'No social, health or income tax advance, and no settlement.');
        self::assertArrayHasKey('flat_rate.advance.2027-01', $flows, 'Next year goes on in the same band.');
    }

    public function testAMatchingBankMovementPaysTheFlatRateAdvance(): void
    {
        $bank = new FakeActualSource('banking.movements', [
            new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'm-nov'), FlowDirection::Out, Money::ofMinor(916_200, Currency::CZK), $this->day('2026-11-19'), counterpartyAccount: self::ACCOUNT, variableSymbol: self::SYMBOL),
        ]);
        $estimates = $this->estimates(new DuePayments([new BankLevyPayments([$bank])]));

        $flows = $this->flows($this->flowsWith($estimates));

        self::assertArrayNotHasKey('flat_rate.advance.2026-11', $flows, 'The November advance is paid by the movement.');
        $paid = array_values(array_filter($estimates->of(2026)->payments, static fn (DuePayment $payment): bool => 'flat_rate.advance.2026-11' === $payment->key()))[0];
        self::assertSame([DuePayment::STATE_PAID, 'm-nov', self::ACCOUNT], [$paid->state, $paid->paidBy, $paid->account]);
        self::assertSame(DuePayment::STATE_OVERDUE, array_values(array_filter($estimates->of(2026)->payments, static fn (DuePayment $payment): bool => 'flat_rate.advance.2026-10' === $payment->key()))[0]->state);
    }

    public function testAMemberSeesFlatRateFlowsAsCategoryTotalsOnly(): void
    {
        $query = new ForecastQuery($this->day('2026-11-20'), $this->day('2026-12-31'));
        $owner = $this->estimateFlows(self::getContainer()->get(ForecastReader::class)->read($query)->flows);
        self::assertNotSame([], $owner);

        $this->signIn($this->member);
        $read = self::getContainer()->get(ForecastReader::class)->read($query);
        $member = $this->estimateFlows($read->flows);

        self::assertContains(EstimateFlows::KEY, $read->restrictedSources);
        self::assertCount(\count($owner), $member);
        foreach ($member as $flow) {
            self::assertSame([null, null, FlowCategory::Taxes], [$flow->subject, $flow->label, $flow->category], 'A member sees the category, never which payment or band it is.');
        }
        self::assertSame($this->total($owner), $this->total($member));
    }

    public function testTheProfileEventsOfTheFlatRateCarryNoAmount(): void
    {
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-11-12'));

        $events = $this->eventData('taxes-and-contributions.profile.changed');
        $all = implode("\n", $events);

        self::assertSame(['what' => 'status', 'kind' => 'flat_rate_band', 'year' => null, 'valid_from' => '2026-01-01'], json_decode($events[1], true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame(['what' => 'authority', 'kind' => 'flat_rate', 'year' => null, 'valid_from' => null], json_decode($events[2], true, flags: \JSON_THROW_ON_ERROR));
        foreach (['9984', '9162', self::ACCOUNT, self::SYMBOL, '"1"'] as $secret) {
            self::assertStringNotContainsString($secret, $all);
        }
        self::assertContains(CzechSoleTrader::WARNING_FLAT_RATE_ENDS, self::getContainer()->get(Estimates::class)->of(2026)->warnings);
        self::assertCount(2, array_filter($this->reads()->profile()->statuses, static fn (\Foresi\TaxesAndContributions\Contract\StatusPeriod $status): bool => 'flat_rate_band' === $status->kind->value || 'vat_payer' === $status->kind->value), 'The estimate never writes a status.');
    }

    private function estimates(DuePayments $payments): Estimates
    {
        $container = self::getContainer();

        return new Estimates($container->get(ProfileReads::class), $container->get(Amounts::class), [new CzechSoleTrader()], $container->get(InstanceClock::class), payments: $payments);
    }

    private function flowsWith(Estimates $estimates): EstimateFlows
    {
        $container = self::getContainer();

        return new EstimateFlows($estimates, $container->get(TaxesAccess::class), $container->get(ApiTokenContext::class), $container->get(TranslatorInterface::class), $container->get(UrlGeneratorInterface::class));
    }

    /**
     * @return array<string, ExpectedFlow>
     */
    private function flows(?EstimateFlows $source = null): array
    {
        $source ??= self::getContainer()->get(EstimateFlows::class);
        $today = $this->day('2026-11-20');
        $flows = [];
        foreach ($source->flows($today, $this->day('2027-01-31'), $today) as $flow) {
            $flows[$flow->subject->id] = $flow;
        }

        return $flows;
    }

    /**
     * @param list<ReadFlow> $flows
     *
     * @return list<ReadFlow>
     */
    private function estimateFlows(array $flows): array
    {
        return array_values(array_filter($flows, static fn (ReadFlow $flow): bool => EstimateFlows::KEY === $flow->source));
    }

    /**
     * @param list<ReadFlow> $flows
     */
    private function total(array $flows): int
    {
        return array_sum(array_map(static fn (ReadFlow $flow): int => $flow->amountCzk->minor(), $flows));
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }
}
