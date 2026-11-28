<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Integration\CashFlow;

use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\UndatedFlow;
use Foresi\Deals\Application\Api\AddDealLineInput;
use Foresi\Deals\Application\Api\CreateBillingEntryInput;
use Foresi\Deals\Application\Api\CreateDealInput;
use Foresi\Deals\Application\Api\CreateDealStageInput;
use Foresi\Deals\Application\Api\UpdateBillingEntryInput;
use Foresi\Deals\Application\Api\WinDealInput;
use Foresi\Deals\Application\BillingPlanWrites;
use Foresi\Deals\Application\DealLineWrites;
use Foresi\Deals\Application\DealReads;
use Foresi\Deals\Application\DealWrites;
use Foresi\Deals\Application\SalesAccess;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Deals\Application\SalesStore;
use Foresi\Deals\Application\StageWrites;
use Foresi\Deals\Domain\BillingPlanEntry;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Integration\CashFlow\BillingPlanFlows;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\TaxesAndContributions\Support\FixedToken;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The billing plan as a cash-flow source: each planned invoice on its day,
 * paid the client's due days later and labelled "Deal – text", a recurring
 * one every interval of its line's unit for its cycles up to the last day
 * asked, and those without a day apart; only staff reads them.
 */
final class BillingPlanFlowsTest extends CashFlowTestCase
{
    private Deal $undated;
    private Deal $later;

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get(PartyInvoicingDefaults::class)->change($this->customer->id(), new InvoicingDefaults(30, Currency::of('CZK'), DocumentLanguage::Czech, PaymentMethod::BankTransfer, false));
        $this->em->flush();

        // Planned in this order, each a second after the one before.
        $this->undated = $this->won('Undated deal', '500.00');
        $this->clock->sleep(1);
        $shop = $this->won('Shop', '10000.00');
        $this->plans()->update(new UpdateBillingEntryInput($shop->id(), $this->entry($shop)->id(), expectedOn: '2026-12-01', text: 'Deposit'));
        $this->clock->sleep(1);
        $retainer = $this->deal('Retainer');
        $this->lines()->add(new AddDealLineInput($retainer->id(), name: 'SEO', unitPrice: '1000.00', billingKind: 'recurring', recurrenceUnit: 'week', interval: 2, cycles: 3));
        $this->deals()->win(new WinDealInput($retainer->id()));
        $this->plans()->update(new UpdateBillingEntryInput($retainer->id(), $this->entry($retainer)->id(), expectedOn: '2026-12-01'));
        $this->clock->sleep(1);
        $free = $this->deal('Free support');
        $support = $this->lines()->add(new AddDealLineInput($free->id(), name: 'Support', unitPrice: '0.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->deals()->win(new WinDealInput($free->id()));
        $this->plans()->create(new CreateBillingEntryInput($free->id(), 'recurring', lineId: $support->id()));
        $this->clock->sleep(1);
        $this->later = $this->won('Later deal', '700.00');
    }

    public function testEachPlannedInvoiceIsPaidTheClientsDueDaysAfterItsDayUpToTheLastDayAsked(): void
    {
        self::assertSame([
            ['Shop – Deposit', '2026-12-31', 1_000_000],
            ['Retainer – SEO (2026-12-01)', '2026-12-31', 100_000],
            ['Retainer – SEO (2026-12-15)', '2027-01-14', 100_000],
            ['Retainer – SEO (2026-12-29)', '2027-01-28', 100_000],
        ], $this->flows('2027-12-31'), 'Every two weeks for three cycles, 30 days after each invoice.');
        self::assertSame([
            ['Shop – Deposit', '2026-12-31', 1_000_000],
            ['Retainer – SEO (2026-12-01)', '2026-12-31', 100_000],
            ['Retainer – SEO (2026-12-15)', '2027-01-14', 100_000],
            ['Retainer – SEO (2026-12-29)', '2027-01-28', 100_000],
        ], $this->flows('2027-01-28'), 'The last day asked counts.');
        self::assertSame([
            ['Shop – Deposit', '2026-12-31', 1_000_000],
            ['Retainer – SEO (2026-12-01)', '2026-12-31', 100_000],
        ], $this->flows('2026-12-31'));
        self::assertSame([], $this->flows('2026-12-30'));
    }

    public function testAClientTheCallerDoesNotSeeIsDueInTheStandardFourteenDays(): void
    {
        $hidden = new class implements PartyDirectory {
            public function find(string $id): ?PartySummary
            {
                return null;
            }

            public function get(string $id): PartySummary
            {
                throw new \LogicException('Not asked.');
            }

            public function personsOf(string $companyId): array
            {
                return [];
            }
        };

        self::assertSame([
            ['Shop – Deposit', '2026-12-15', 1_000_000],
            ['Retainer – SEO (2026-12-01)', '2026-12-15', 100_000],
        ], $this->flows('2026-12-15', $this->source($hidden)));
    }

    public function testThoseWithoutADayComeApartAndAFreeLineIsNone(): void
    {
        $flows = [...$this->source()->undatedFlows($this->day('2026-11-17'))];

        self::assertSame([
            ['Undated deal', 50_000, $this->customer->id()],
            ['Later deal', 70_000, $this->customer->id()],
        ], array_map(static fn (UndatedFlow $flow): array => [$flow->label, $flow->amount->minor(), $flow->counterpartyId], $flows));
        self::assertSame([BillingPlanFlows::SUBJECT, 'Undated deal'], [$flows[0]->subject->type, $flows[0]->subject->label]);
        self::assertSame($this->entry($this->undated)->id(), $flows[0]->subject->id);
        self::assertSame($this->entry($this->later)->id(), $flows[1]->subject->id);
    }

    public function testATokenWithoutSalesReadsNoDetails(): void
    {
        self::assertTrue($this->source()->mayReadDetails());
        self::assertTrue($this->source(tokens: new FixedToken([ApiScope::from('deals:read')]))->mayReadDetails());
        self::assertFalse($this->source(tokens: new FixedToken([ApiScope::from('cashflow:read')]))->mayReadDetails());
    }

    public function testOnlyStaffReadsThePlan(): void
    {
        $this->signIn($this->guest);
        $source = $this->source();

        foreach ([
            static fn (): array => [...$source->flows(new \DateTimeImmutable('2026-11-17'), new \DateTimeImmutable('2027-11-17'), new \DateTimeImmutable('2026-11-17'))],
            static fn (): array => [...$source->undatedFlows(new \DateTimeImmutable('2026-11-17'))],
        ] as $read) {
            try {
                $read();
                self::fail('Expected a guest to be refused.');
            } catch (SalesRefused $refused) {
                self::assertSame('forbidden', $refused->reason());
            }
        }
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function flows(string $to, ?BillingPlanFlows $source = null): array
    {
        $today = $this->day('2026-11-17');

        return array_values(array_map(
            static fn (ExpectedFlow $flow): array => [$flow->label, $flow->expectedOn->format('Y-m-d'), $flow->amount->minor()],
            [...($source ?? $this->source())->flows($today, $this->day($to), $today)],
        ));
    }

    private function source(?PartyDirectory $parties = null, ?ApiTokenContext $tokens = null): BillingPlanFlows
    {
        $container = self::getContainer();

        return new BillingPlanFlows($container->get(SalesAccess::class), $container->get(SalesStore::class), $parties ?? $container->get(PartyDirectory::class), $tokens ?? $container->get(ApiTokenContext::class), $container->get(UrlGeneratorInterface::class));
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }

    private function entry(Deal $deal): BillingPlanEntry
    {
        return self::getContainer()->get(DealReads::class)->deal($deal->id())->billingPlan->entries[0]->entry;
    }

    private function won(string $title, string $price): Deal
    {
        $deal = $this->deal($title);
        $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Audit', unit: 'piece', quantity: '1', unitPrice: $price, currency: 'CZK'));

        return $this->deals()->win(new WinDealInput($deal->id()));
    }

    private function deal(string $title): Deal
    {
        $stage = self::getContainer()->get(StageWrites::class)->create(new CreateDealStageInput('Inquiry', 'inquiry_'.substr(md5($title), 0, 6)));

        return $this->deals()->create(new CreateDealInput($title, $this->customer->id(), $stage->id()));
    }

    private function deals(): DealWrites
    {
        return self::getContainer()->get(DealWrites::class);
    }

    private function lines(): DealLineWrites
    {
        return self::getContainer()->get(DealLineWrites::class);
    }

    private function plans(): BillingPlanWrites
    {
        return self::getContainer()->get(BillingPlanWrites::class);
    }
}
