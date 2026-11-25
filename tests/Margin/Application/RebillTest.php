<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Instance\Application\ConfiguredZone;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\ZoneFallback;
use Foresi\Invoicing\Contract\InvoicingSlots;
use Foresi\Margin\Application\CostPairings;
use Foresi\Margin\Application\DropRebillOnTrash;
use Foresi\Margin\Application\MappingVersions;
use Foresi\Margin\Application\MarginAccess;
use Foresi\Margin\Application\MarginCell;
use Foresi\Margin\Application\MarginRanges;
use Foresi\Margin\Application\MarginTable;
use Foresi\Margin\Application\PairRebillOnIssue;
use Foresi\Margin\Application\PlannedCostItem;
use Foresi\Margin\Application\RebillLinks;
use Foresi\Margin\Application\RebillPairings;
use Foresi\Margin\Application\RevenueSources;
use Foresi\Margin\Application\Unrebilled;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Contract\RevenueLine;
use Foresi\Margin\Contract\TemplateLine;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Domain\RebillLink;
use Foresi\Margin\Domain\RebillPairing;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Margin\Infrastructure\Screen\MarginScreen;
use Foresi\Margin\Integration\Invoicing\RebillPanel;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Tests\Margin\Support\FixedPairings;
use Foresi\Tests\Margin\Support\FixedRevenueSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * Rebilling: the links of template lines to planned items, the pairing of
 * issued lines with the occurrence they rebill and the occurrences that were
 * paid and not rebilled, on a real database with invoices, template lines and
 * cost pairings the test names, so neither Invoicing nor Cash flow is needed.
 * Cloud Host is split between Client A and Client B from January 2026; today
 * is 12 November 2026 in Prague.
 */
final class RebillTest extends KernelTestCase
{
    private const string CLOUD_HOST = '0197a1de-0000-7000-8000-0000000000e2';
    private const string RETAINER = '0197a1de-0000-7000-8000-0000000000e3';
    private const string LINE_A = '0197a1de-0000-7000-8000-0000000000f1';
    private const string LINE_B = '0197a1de-0000-7000-8000-0000000000f2';
    private const string TEMPLATE_A = '0197a1de-0000-7000-8000-0000000000f3';
    private const string VOICE_API = '0197a1de-0000-7000-8000-0000000000e5';
    private const string LINE_A2 = '0197a1de-0000-7000-8000-0000000000f6';
    private const string LINE_CAPITALS = '0197a1de-0000-7000-8000-0000000000f7';

    private const string ELSEWHERE = '0197a1de-0000-7000-8000-00000000a001';
    private const string L1 = '0197a1de-0000-7000-8000-00000000a002';
    private const string PAIRED = '0197a1de-0000-7000-8000-00000000a003';
    private const string L2 = '0197a1de-0000-7000-8000-00000000a004';
    private const string L3 = '0197a1de-0000-7000-8000-00000000a005';
    private const string HAND = '0197a1de-0000-7000-8000-00000000a000';
    private const string HAND2 = '0197a1de-0000-7000-8000-00000000a007';
    private const string OTHER = '0197a1de-0000-7000-8000-00000000a008';
    private const string UNLINKED = '0197a1de-0000-7000-8000-00000000a009';
    private const string VOICE_LINE = '0197a1de-0000-7000-8000-00000000a010';
    private const string I0 = '0197a1de-0000-7000-8000-00000000b000';
    private const string I1 = '0197a1de-0000-7000-8000-00000000b001';
    private const string I2 = '0197a1de-0000-7000-8000-00000000b002';
    private const string COST_1 = '0197a1de-0000-7000-8000-00000000c001';
    private const string COST_2 = '0197a1de-0000-7000-8000-00000000c002';
    private const string COST_3 = '0197a1de-0000-7000-8000-00000000c003';
    private const string COST_NOV = '0197a1de-0000-7000-8000-00000000c004';
    private const string COST_OCT = '0197a1de-0000-7000-8000-00000000c005';

    private EntityManagerInterface $em;
    private FixedRevenueSource $revenue;
    private FixedPairings $pairings;
    private RebillLinks $links;
    private PairRebillOnIssue $pairOnIssue;
    private RebillPairings $manual;
    private Unrebilled $unrebilled;
    private MarginTable $table;
    private MarginCell $cell;
    private string $clientA;
    private string $clientB;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        Clock::set(new MockClock('2026-11-12 09:00:00', 'UTC'));
        InstanceZone::set($container);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->clientA = $this->contact('Client A');
        $this->clientB = $this->contact('Client B');
        $cloudHost = $this->contact('Cloud Host');
        $user = new User('owner@example.com', 'hash', 'Olga Owner');
        $this->em->persist($user);
        $this->em->persist(new Membership($user, Role::Owner));
        $this->em->flush();
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));

        $this->revenue = new FixedRevenueSource();
        $this->revenue->templateLines[self::LINE_A] = new TemplateLine(self::LINE_A, self::TEMPLATE_A, $this->clientA, 'Cloud Host', '/invoices/recurring/'.self::TEMPLATE_A);
        $this->revenue->templateLines[self::LINE_B] = new TemplateLine(self::LINE_B, '0197a1de-0000-7000-8000-0000000000f4', $this->clientB, 'Cloud Host', '/invoices/recurring/0197a1de-0000-7000-8000-0000000000f4');
        $this->pairings = new FixedPairings();
        $this->pairings->items[self::CLOUD_HOST] = new PlannedCostItem(self::CLOUD_HOST, 'Cloud Host', true, true, $cloudHost, '/cash-flow/items/'.self::CLOUD_HOST);
        $this->pairings->items[self::RETAINER] = new PlannedCostItem(self::RETAINER, 'Retainer', false, true, $this->clientA);
        $this->em->persist(new PlannedAllocation(Uuid::v7()->toRfc4122(), self::CLOUD_HOST, new \DateTimeImmutable('2026-01-01'), new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client($this->clientA), '50'),
            new AllocationShare(Recipient::client($this->clientB), '50'),
        ]), new \DateTimeImmutable(), 'user'));
        $this->em->flush();

        $revenue = new RevenueSources([$this->revenue]);
        $costPairings = new CostPairings([$this->pairings]);
        $mappings = new MappingVersions($this->em);
        $access = $container->get(MarginAccess::class);
        $ranges = new MarginRanges($container->get(InstanceClock::class));
        $transaction = $container->get(TransactionalWork::class);
        $this->links = new RebillLinks($access, $revenue, $costPairings, $mappings, $this->em, $container->get(InstanceClock::class), $transaction);
        $this->pairOnIssue = new PairRebillOnIssue($revenue, $costPairings, $mappings, $this->em);
        $this->manual = new RebillPairings($access, $revenue, $costPairings, $mappings, $this->em, $transaction, $container->get(InstanceClock::class));
        $this->unrebilled = new Unrebilled($access, $ranges, $revenue, $costPairings, $mappings, $this->em);
        $costs = new \Foresi\Margin\Application\CostShareReads($this->em);
        $sources = new \Foresi\Margin\Application\CostSources([]);
        $calculation = new \Foresi\Margin\Application\CostShareCalculation($this->em, $sources, new \Foresi\Margin\Domain\ShareSplitter(), $container->get(InstanceClock::class), $costPairings, $mappings, new \Foresi\Margin\Application\OccurrenceFill(new \Foresi\Margin\Domain\ShareSplitter()));
        $directory = $container->get(PartyDirectory::class);
        $this->table = new MarginTable($access, $ranges, $revenue, $costs, $calculation, $sources, new \Foresi\Margin\Application\KnownGaps($sources), $directory, $this->unrebilled);
        $this->cell = new MarginCell($access, $ranges, $revenue, $costs, $sources, $directory, $this->unrebilled);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnIssuedLinePairsWithTheOccurrenceOfItsTaxPoint(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->holdMonth('2026-12');
        // The tax point is 30 November in Prague, which is still 29 November in UTC for the first hour of the day.
        $november = $this->invoice(self::I1, $this->clientA, '2026-11-30', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK)), new RevenueLine(self::L2, null, Money::ofMinor(5_000, Currency::CZK))]);
        $this->invoice(self::I2, $this->clientA, '2026-12-01', [new RevenueLine(self::L3, self::LINE_A, Money::ofMinor(100_000, Currency::CZK))]);

        self::assertSame('2026-11-30T00:00:00+01:00', $november->taxPointOn->format(\DATE_ATOM), 'the invoice is in the hour between Prague and UTC midnight');
        self::assertSame('2026-11-29', $november->taxPointOn->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'));
        self::assertSame(1, $this->pairOnIssue->pair(self::I1));
        self::assertSame(1, $this->pairOnIssue->pair(self::I2));
        $this->em->flush();

        $pairings = $this->em->getRepository(RebillPairing::class)->findBy([], ['invoiceLineId' => 'ASC']);
        self::assertSame([self::L1.' 2026-11-01 '.self::I1.' false', self::L3.' 2026-12-01 '.self::I2.' false'], array_map(static fn (RebillPairing $pairing): string => $pairing->invoiceLineId().' '.$pairing->occurrenceOn()->format('Y-m-d').' '.$pairing->invoiceId().' '.($pairing->manual() ? 'true' : 'false'), $pairings));
        self::assertSame([$this->clientA, self::CLOUD_HOST], [$pairings[0]->customerId(), $pairings[0]->plannedItemId()]);
    }

    public function testACustomerWhoIsNotARecipientIsNotPaired(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $stranger = $this->contact('Client C');
        $this->invoice(self::I1, $stranger, '2026-11-10', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK))]);

        self::assertSame(0, $this->pairOnIssue->pair(self::I1));
        $this->em->flush();

        self::assertSame([], $this->em->getRepository(RebillPairing::class)->findAll());
    }

    public function testALineWithoutALinkOrAnOccurrenceIsNotPaired(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->invoice(self::I1, $this->clientA, '2026-11-10', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK)), new RevenueLine(self::L2, self::LINE_B, Money::ofMinor(100_000, Currency::CZK))]);

        self::assertSame(0, $this->pairOnIssue->pair(self::I1), 'no occurrence holds the day, and line B has no link');
        self::assertSame(0, $this->pairOnIssue->pair('unknown'));
    }

    public function testASecondDeliveryDoesNotPairAgain(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->invoice(self::I1, $this->clientA, '2026-11-10', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK))]);

        self::assertSame(1, $this->pairOnIssue->pair(self::I1));
        $this->em->flush();
        self::assertSame(0, $this->pairOnIssue->pair(self::I1));
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(RebillPairing::class)->findAll());
    }

    public function testPaidNotRebilledIsFlaggedPerRecipient(): void
    {
        // Cloud Host is split between Client A and Client B; only Client A has a rebill line.
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');

        $flagged = $this->unrebilled->between('2026-11', '2026-11');

        self::assertSame([[self::CLOUD_HOST, 'Cloud Host', '/cash-flow/items/'.self::CLOUD_HOST, '2026-11-01', '2026-11', $this->clientA, self::TEMPLATE_A, self::LINE_A]], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): array => [$occurrence->plannedItemId, $occurrence->itemName, $occurrence->itemUrl, $occurrence->occurrenceOn->format('Y-m-d'), $occurrence->month, $occurrence->recipientId, $occurrence->templateId, $occurrence->recurringLineId], $flagged));
    }

    public function testBothRecipientsWithARebillLineAreFlaggedEach(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->links->set(self::LINE_B, self::CLOUD_HOST);
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');

        self::assertEqualsCanonicalizing([$this->clientA, $this->clientB], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->recipientId, $this->unrebilled->between('2026-11', '2026-11')));
    }

    public function testARebilledOccurrenceIsNotFlagged(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK))]);
        $this->pairOnIssue->pair(self::I1);
        $this->em->flush();

        self::assertSame([], $this->unrebilled->between('2026-11', '2026-11'));
    }

    public function testUnpairingFlagsTheOccurrenceAgain(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK))]);
        $this->pairOnIssue->pair(self::I1);
        $this->em->flush();

        $this->manual->unpair(self::L1);

        self::assertCount(1, $this->unrebilled->between('2026-11', '2026-11'));
        $this->assertRefusal(MarginRefused::UNKNOWN_PAIRING, fn () => $this->manual->unpair(self::L1));
    }

    public function testAnOccurrenceWithoutAPairedCostIsNotFlagged(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');

        self::assertSame([], $this->unrebilled->between('2026-11', '2026-11'));
    }

    public function testAnOccurrenceBeforeTheMonthOfTheLinkIsNotFlagged(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->pairings->pair(self::COST_OCT, self::CLOUD_HOST, '2026-10-01');
        $this->pairings->pair(self::COST_NOV, self::CLOUD_HOST, '2026-11-01');

        self::assertSame(['2026-11'], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->month, $this->unrebilled->between('2026-10', '2026-11')), 'The link was made on 12 November: November counts, October does not.');
    }

    public function testALinkMadeAfterPragueMidnightCountsFromTheNewMonth(): void
    {
        Clock::set(new MockClock('2026-11-30 22:30:00', 'UTC'));
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        self::assertSame('2026-11-30', $this->links->ofLine(self::LINE_A)?->createdOn, '23:30 on 30 November in Prague');
        $this->links->set(self::LINE_B, self::CLOUD_HOST);
        Clock::set(new MockClock('2026-11-30 23:30:00', 'UTC'));
        $this->links->set(self::LINE_A, null);
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        self::assertSame('2026-12-01', $this->links->set(self::LINE_A, self::CLOUD_HOST)?->createdOn, '00:30 on 1 December in Prague');
        $this->pairings->pair(self::COST_NOV, self::CLOUD_HOST, '2026-11-01');

        self::assertSame([$this->clientB], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->recipientId, $this->unrebilled->between('2026-11', '2026-11')), 'Client B was linked in November, Client A only in December.');
    }

    public function testARecipientDroppedFromTheMappingIsNotFlagged(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->pairings->pair(self::COST_NOV, self::CLOUD_HOST, '2026-11-01');
        // From November the whole cost goes to Client B.
        $this->em->persist(new PlannedAllocation(Uuid::v7()->toRfc4122(), self::CLOUD_HOST, new \DateTimeImmutable('2026-11-01'), new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client($this->clientB))]), new \DateTimeImmutable(), 'user'));
        $this->em->flush();

        self::assertSame([], $this->unrebilled->between('2026-11', '2026-11'));
    }

    public function testTheMarginTableCountsTheFlaggedOccurrencesPerClientAndMonth(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->pairings->pair(self::COST_NOV, self::CLOUD_HOST, '2026-11-01');

        $table = MarginJson::table($this->table->of('2026-10', '2026-11'));

        $a = 'client:'.$this->clientA;
        self::assertSame([0, 1], $this->unrebilledOf($table['rows'], $a));
        self::assertSame([0, 0], $this->unrebilledOf($table['rows'], 'own'));
        self::assertSame([0, 0], $this->unrebilledOf($table['rows'], 'unassigned'));
        self::assertSame([0, 1], array_map(static fn (mixed $cell): mixed => \is_array($cell) ? $cell['unrebilled'] : null, \is_array($table['month_totals']) ? $table['month_totals'] : []));

        $flagged = $this->cell->of($a, '2026-11')->unrebilled;
        self::assertSame([['Cloud Host', '2026-11-01', self::TEMPLATE_A, '/invoices/recurring/'.self::TEMPLATE_A, self::LINE_A]], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): array => [$occurrence->itemName, $occurrence->occurrenceOn->format('Y-m-d'), $occurrence->templateId, $occurrence->templateUrl, $occurrence->recurringLineId], $flagged));
        self::assertSame(1, $this->cell->of($a, '2026-11')->figures->unrebilled);
        $detail = MarginJson::cellDetail($this->cell->of($a, '2026-11'));
        self::assertSame(['Cloud Host', '2026-11-01', self::TEMPLATE_A, '/invoices/recurring/'.self::TEMPLATE_A], [$this->in($detail, 'unrebilled', 0, 'label'), $this->in($detail, 'unrebilled', 0, 'occurrence_on'), $this->in($detail, 'unrebilled', 0, 'template_id'), $this->in($detail, 'unrebilled', 0, 'template_url')]);
        self::assertSame([1, 1, 0], [$this->in($table, 'total', 'unrebilled'), $this->in($table, 'rows', 0, 'total', 'unrebilled'), $this->in($table, 'rows', 1, 'total', 'unrebilled')]);
        self::assertSame([], MarginJson::cellDetail($this->cell->of($a, '2026-10'))['unrebilled']);
        self::assertSame([], MarginJson::cellDetail($this->cell->of('own', '2026-11'))['unrebilled']);
    }

    public function testAHandWrittenLineCanBePaired(): void
    {
        $this->holdMonth('2026-11');
        $this->pairings->pair(self::COST_NOV, self::CLOUD_HOST, '2026-11-01');
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::HAND, null, Money::ofMinor(100_000, Currency::CZK))]);

        $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11-01');

        $pairing = $this->em->getRepository(RebillPairing::class)->findOneBy(['invoiceLineId' => self::HAND]);
        self::assertInstanceOf(RebillPairing::class, $pairing);
        self::assertSame([true, self::I1, $this->clientA, '2026-11-01'], [$pairing->manual(), $pairing->invoiceId(), $pairing->customerId(), $pairing->occurrenceOn()->format('Y-m-d')]);
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        self::assertSame([], $this->unrebilled->between('2026-11', '2026-11'), 'Pairing by hand counts as rebilled.');
    }

    public function testAHandPairingIsRefusedWhenItCannotBeRight(): void
    {
        $this->holdMonth('2026-11');
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::HAND, null, Money::ofMinor(100_000, Currency::CZK))]);
        $stranger = $this->contact('Client C');
        $this->invoice(self::I2, $stranger, '2026-11-20', [new RevenueLine(self::OTHER, null, Money::ofMinor(100_000, Currency::CZK))]);

        $this->assertRefusal(MarginRefused::UNKNOWN_INVOICE_LINE, fn (): \Foresi\Margin\Application\RebillPairingView => $this->manual->pair('nope', self::CLOUD_HOST, '2026-11-01'));
        $this->assertRefusal(MarginRefused::UNKNOWN_PLANNED_ITEM, fn (): \Foresi\Margin\Application\RebillPairingView => $this->manual->pair(self::HAND, '0197a1de-0000-7000-8000-0000000000e9', '2026-11-01'));
        $this->assertRefusal(MarginRefused::INVALID_DAY, fn (): \Foresi\Margin\Application\RebillPairingView => $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11'));
        $this->assertRefusal(MarginRefused::NOT_AN_OCCURRENCE, fn (): \Foresi\Margin\Application\RebillPairingView => $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-12-01'));
        $this->assertRefusal(MarginRefused::CUSTOMER_NOT_A_RECIPIENT, fn (): \Foresi\Margin\Application\RebillPairingView => $this->manual->pair(self::OTHER, self::CLOUD_HOST, '2026-11-01'));
        $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11-01');
        $this->assertRefusal(MarginRefused::ALREADY_PAIRED, fn (): \Foresi\Margin\Application\RebillPairingView => $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11-01'));
        self::assertCount(1, $this->em->getRepository(RebillPairing::class)->findAll());
    }

    public function testAHandPairingTakesAnyDayOfThePeriodAndStoresTheOccurrence(): void
    {
        $this->holdMonth('2026-11');
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::HAND, null, Money::ofMinor(100_000, Currency::CZK))]);

        $view = $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11-20');

        self::assertSame('2026-11-01', $view->occurrenceOn);
        $stored = $this->em->getRepository(RebillPairing::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame('2026-11-01', $stored[0]->occurrenceOn()->format('Y-m-d'));
    }

    public function testALinkIsSetReadChangedAndRemoved(): void
    {
        $view = $this->links->set(self::LINE_A, self::CLOUD_HOST);

        self::assertSame([self::LINE_A, self::CLOUD_HOST, 'Cloud Host', '2026-11-12', []], [$view?->recurringLineId, $view?->plannedItemId, $view?->plannedItemName, $view?->createdOn, $view?->warnings]);
        self::assertSame(['Cloud Host'], array_map(static fn (\Foresi\Margin\Application\RebillLinkView $view): string => $view->plannedItemName, array_values($this->links->ofLines([self::LINE_A, self::LINE_B]))));
        self::assertNull($this->links->ofLine(self::LINE_B));

        Clock::set(new MockClock('2026-11-20 09:00:00', 'UTC'));
        self::assertSame('2026-11-12', $this->links->set(self::LINE_A, self::CLOUD_HOST)?->createdOn, 'Setting the same item again keeps the link.');
        $this->pairings->items[self::RETAINER] = new PlannedCostItem(self::RETAINER, 'Retainer', true, true, $this->clientA);
        self::assertSame('2026-11-20', $this->links->set(self::LINE_A, self::RETAINER)?->createdOn, 'Another item starts the link over.');
        self::assertCount(1, $this->em->getRepository(RebillLink::class)->findAll());

        self::assertNull($this->links->set(self::LINE_A, null));
        self::assertNull($this->links->ofLine(self::LINE_A));
        self::assertNull($this->links->set(self::LINE_A, null), 'Removing what is not there is fine.');
    }

    public function testALinkIsRefusedForWhatDoesNotExistOrIsNoCost(): void
    {
        $this->assertRefusal(MarginRefused::UNKNOWN_TEMPLATE_LINE, fn (): ?\Foresi\Margin\Application\RebillLinkView => $this->links->set('0197a1de-0000-7000-8000-0000000000f9', self::CLOUD_HOST));
        $this->assertRefusal(MarginRefused::UNKNOWN_PLANNED_ITEM, fn (): ?\Foresi\Margin\Application\RebillLinkView => $this->links->set(self::LINE_A, '0197a1de-0000-7000-8000-0000000000e9'));
        $this->assertRefusal(MarginRefused::NOT_AN_EXPENSE, fn (): ?\Foresi\Margin\Application\RebillLinkView => $this->links->set(self::LINE_A, self::RETAINER));
        self::assertSame([], $this->em->getRepository(RebillLink::class)->findAll());
    }

    public function testACustomerWhoBearsNothingOfTheItemIsWarnedButTheLinkIsStored(): void
    {
        $other = '0197a1de-0000-7000-8000-0000000000f5';
        $this->revenue->templateLines[$other] = new TemplateLine($other, self::TEMPLATE_A, $this->contact('Client C'), 'Cloud Host', '/invoices/recurring/'.self::TEMPLATE_A);

        $view = $this->links->set($other, self::CLOUD_HOST);

        self::assertSame(['customer_not_a_recipient'], $view?->warnings);
        self::assertSame(['customer_not_a_recipient'], $this->links->ofLine($other)?->warnings);
        self::assertSame([], $this->links->set(self::LINE_A, self::CLOUD_HOST)?->warnings);
    }

    public function testAZoneWestOfUtcPairsTheDayOfTheTaxPointAndTheDayPickedByHand(): void
    {
        $container = self::getContainer();
        $zone = new \DateTimeZone('America/New_York');
        $this->pairings->zone = $zone;
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->holdMonth('2026-12');
        // 1 December in New York: the same date read as midnight UTC would be 19:00 on 30 November there.
        $this->invoice(self::I1, $this->clientA, '2026-12-01', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK)), new RevenueLine(self::HAND, null, Money::ofMinor(5_000, Currency::CZK))], $zone);

        self::assertSame(1, $this->pairOnIssue->pair(self::I1));
        $this->em->flush();
        $pairing = $this->em->getRepository(RebillPairing::class)->findOneBy(['invoiceLineId' => self::L1]);
        self::assertSame('2026-12-01', $pairing?->occurrenceOn()->format('Y-m-d'), 'The tax point of 1 December pairs with the December occurrence.');

        $manual = new RebillPairings($container->get(MarginAccess::class), new RevenueSources([$this->revenue]), new CostPairings([$this->pairings]), new MappingVersions($this->em), $this->em, $container->get(TransactionalWork::class), new InstanceClock(new ConfiguredZone('America/New_York'), new class implements ZoneFallback {
            public function zone(): ?\DateTimeZone
            {
                return null;
            }
        }, new Clock()));
        $manual->pair(self::HAND, self::CLOUD_HOST, '2026-12-01');
        self::assertSame('2026-12-01', $this->em->getRepository(RebillPairing::class)->findOneBy(['invoiceLineId' => self::HAND])?->occurrenceOn()->format('Y-m-d'));
    }

    public function testAnOrphanLinkCanBeRemoved(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        unset($this->revenue->templateLines[self::LINE_A]);

        self::assertNull($this->links->set(self::LINE_A, null));
        self::assertSame([], $this->em->getRepository(RebillLink::class)->findAll());
        $this->assertRefusal(MarginRefused::UNKNOWN_TEMPLATE_LINE, fn (): ?\Foresi\Margin\Application\RebillLinkView => $this->links->set(self::LINE_A, self::CLOUD_HOST));
    }

    public function testAHandPairingToAnIncomeItemIsRefused(): void
    {
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::HAND, null, Money::ofMinor(100_000, Currency::CZK))]);

        $this->assertRefusal(MarginRefused::NOT_AN_EXPENSE, fn (): \Foresi\Margin\Application\RebillPairingView => $this->manual->pair(self::HAND, self::RETAINER, '2026-11-01'));
    }

    public function testTheTrashedInvoiceRebillsNothingUntilItIsRestored(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK))]);
        $this->pairOnIssue->pair(self::I1);
        $this->em->flush();
        self::assertSame([], $this->unrebilled->between('2026-11', '2026-11'));

        $drop = new DropRebillOnTrash($this->em);
        self::assertSame(1, $drop->drop(self::I1));
        $this->em->flush();
        self::assertCount(1, $this->unrebilled->between('2026-11', '2026-11'), 'The invoice in the trash does not count as rebilled.');
        self::assertSame(0, $drop->drop(self::I1), 'A second delivery removes nothing.');

        self::assertSame(1, $this->pairOnIssue->pair(self::I1), 'Restored, its line is paired again.');
        $this->em->flush();
        self::assertSame([], $this->unrebilled->between('2026-11', '2026-11'));
    }

    public function testAGuestIsRefused(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);

        $this->assertRefusal(MarginRefused::FORBIDDEN, fn (): ?\Foresi\Margin\Application\RebillLinkView => $this->links->set(self::LINE_A, self::CLOUD_HOST));
        $this->assertRefusal(MarginRefused::FORBIDDEN, fn (): array => $this->links->ofLines([self::LINE_A]));
        $this->assertRefusal(MarginRefused::FORBIDDEN, fn () => $this->manual->unpair(self::L1));
        $this->assertRefusal(MarginRefused::FORBIDDEN, fn (): array => $this->unrebilled->between('2026-11', '2026-11'));
        $this->assertRefusal(MarginRefused::FORBIDDEN, fn (): \Foresi\Margin\Application\RebillPairingView => $this->manual->pair(self::L1, self::CLOUD_HOST, '2026-11-01'));
        $this->assertRefusal(MarginRefused::FORBIDDEN, fn (): array => $this->manual->ofLines([self::L1]));
    }

    public function testACustomerIdWrittenInCapitalsIsTheCustomerOfTheMapping(): void
    {
        $capitals = strtoupper($this->clientA);
        $this->revenue->templateLines[self::LINE_CAPITALS] = new TemplateLine(self::LINE_CAPITALS, self::TEMPLATE_A, $capitals, 'Cloud Host', '/invoices/recurring/'.self::TEMPLATE_A);
        self::assertSame([], $this->links->set(self::LINE_CAPITALS, self::CLOUD_HOST)?->warnings, 'The customer bears a share, however its id is written.');

        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');
        self::assertSame([$this->clientA], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->recipientId, $this->unrebilled->between('2026-11', '2026-11')));

        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->invoice(self::I1, $capitals, '2026-11-10', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK)), new RevenueLine(self::HAND, null, Money::ofMinor(5_000, Currency::CZK))]);
        self::assertSame(1, $this->pairOnIssue->pair(self::I1));
        $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11-01');
        $this->em->flush();

        $stored = $this->em->getRepository(RebillPairing::class)->findBy([], ['invoiceLineId' => 'ASC']);
        self::assertSame([self::HAND.' '.$this->clientA, self::L1.' '.$this->clientA], array_map(static fn (RebillPairing $pairing): string => $pairing->invoiceLineId().' '.$pairing->customerId(), $stored), 'The customer is stored as the mapping writes it.');
    }

    public function testALineThatCannotBePairedDoesNotStopTheLinesAfterIt(): void
    {
        $this->addVoiceApi();
        $this->links->set(self::LINE_B, self::CLOUD_HOST);
        $this->links->set(self::LINE_A2, self::VOICE_API);
        $this->holdMonth('2026-11');
        $this->holdMonth('2026-11', self::VOICE_API);
        // A pairing of another line exists, and so does the one of the line "paired".
        $this->em->persist(new RebillPairing(Uuid::v7()->toRfc4122(), self::ELSEWHERE, self::I0, $this->clientB, self::CLOUD_HOST, new \DateTimeImmutable('2026-11-01'), false));
        $this->em->persist(new RebillPairing(Uuid::v7()->toRfc4122(), self::PAIRED, self::I1, $this->clientB, self::CLOUD_HOST, new \DateTimeImmutable('2026-11-01'), false));
        $this->em->flush();
        $money = Money::ofMinor(100_000, Currency::CZK);
        // Client B bears a share of Cloud Host but not of Voice API.
        $this->invoice(self::I1, $this->clientB, '2026-11-10', [new RevenueLine(self::HAND, null, $money), new RevenueLine(self::UNLINKED, self::LINE_A, $money), new RevenueLine(self::VOICE_LINE, self::LINE_A2, $money), new RevenueLine(self::PAIRED, self::LINE_B, $money), new RevenueLine(self::L1, self::LINE_B, $money)]);

        self::assertSame(1, $this->pairOnIssue->pair(self::I1));
        $this->em->flush();

        self::assertSame([self::ELSEWHERE, self::L1, self::PAIRED], array_map(static fn (RebillPairing $pairing): string => $pairing->invoiceLineId(), $this->em->getRepository(RebillPairing::class)->findBy([], ['invoiceLineId' => 'ASC'])));
    }

    public function testAChangeInsideAnOuterTransactionIsFlushedWhenTheCallReturns(): void
    {
        $this->holdMonth('2026-11');
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::HAND, null, Money::ofMinor(100_000, Currency::CZK))]);
        $connection = $this->em->getConnection();
        $rows = static fn (string $table): mixed => $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        $link = $this->em->getClassMetadata(RebillLink::class)->getTableName();
        $pairing = $this->em->getClassMetadata(RebillPairing::class)->getTableName();

        $connection->beginTransaction();
        try {
            $this->links->set(self::LINE_A, self::CLOUD_HOST);
            self::assertEquals(1, $rows($link), 'A link made.');
            $this->links->set(self::LINE_A, null);
            self::assertEquals(0, $rows($link), 'A link removed.');
            $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11-01');
            self::assertEquals(1, $rows($pairing), 'A pairing made.');
            $this->manual->unpair(self::HAND);
            self::assertEquals(0, $rows($pairing), 'A pairing removed.');
        } finally {
            $connection->rollBack();
        }
    }

    public function testTheOccurrencesNotRebilledAreListedByDayThenItemThenClient(): void
    {
        $this->addVoiceApi();
        // The line of the highest id is linked first, so the order of the list comes from the sort, not from the links.
        $this->links->set(self::LINE_A2, self::VOICE_API);
        $this->links->set(self::LINE_B, self::CLOUD_HOST);
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');
        $this->pairings->pair(self::COST_2, self::CLOUD_HOST, '2026-11-15');
        $this->pairings->pair(self::COST_3, self::VOICE_API, '2026-11-01');
        [$first, $second] = [$this->clientA, $this->clientB];
        if ($first > $second) {
            [$first, $second] = [$second, $first];
        }

        $found = array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->occurrenceOn->format('Y-m-d').' '.$occurrence->itemName.' '.$occurrence->recipientId, $this->unrebilled->between('2026-11', '2026-11'));

        self::assertSame([
            '2026-11-01 Cloud Host '.$first,
            '2026-11-01 Cloud Host '.$second,
            '2026-11-01 Voice API '.$this->clientA,
            '2026-11-15 Cloud Host '.$first,
            '2026-11-15 Cloud Host '.$second,
        ], $found);
    }

    public function testAnOccurrenceOfAClientWithTwoLinesIsFlaggedOnceByTheLineOfTheLowestId(): void
    {
        $this->addVoiceApi();
        $this->links->set(self::LINE_A2, self::CLOUD_HOST);
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');

        $found = $this->unrebilled->between('2026-11', '2026-11');

        self::assertSame([self::LINE_A], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->recurringLineId, $found));
    }

    public function testADayThatIsRebilledOrNotForTheClientDoesNotHideTheDaysAfterIt(): void
    {
        $this->addVoiceApi();
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');
        $this->pairings->pair(self::COST_2, self::CLOUD_HOST, '2026-11-15');
        // 1 November of Cloud Host is rebilled; Client A rebilled 15 November of Voice API, which is another cost.
        $this->em->persist(new RebillPairing(Uuid::v7()->toRfc4122(), self::L1, self::I1, $this->clientA, self::CLOUD_HOST, new \DateTimeImmutable('2026-11-01'), false));
        $this->em->persist(new RebillPairing(Uuid::v7()->toRfc4122(), self::L2, self::I1, $this->clientA, self::VOICE_API, new \DateTimeImmutable('2026-11-15'), false));
        $this->em->flush();

        self::assertSame(['2026-11-15'], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->occurrenceOn->format('Y-m-d'), $this->unrebilled->between('2026-11', '2026-11')));
    }

    public function testALinkWhoseLineOrItemIsGoneFlagsNothingAndDoesNotStopTheOthers(): void
    {
        $this->addVoiceApi();
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->links->set(self::LINE_B, self::CLOUD_HOST);
        $this->links->set(self::LINE_A2, self::VOICE_API);
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');
        $this->pairings->pair(self::COST_2, self::VOICE_API, '2026-11-01');
        $recipients = fn (): array => array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->itemName.' '.$occurrence->recipientId, $this->unrebilled->between('2026-11', '2026-11'));
        $all = ['Cloud Host '.min($this->clientA, $this->clientB), 'Cloud Host '.max($this->clientA, $this->clientB), 'Voice API '.$this->clientA];
        self::assertSame($all, $recipients());

        $line = $this->revenue->templateLines[self::LINE_A];
        unset($this->revenue->templateLines[self::LINE_A]);
        self::assertSame(['Cloud Host '.$this->clientB, 'Voice API '.$this->clientA], $recipients(), 'The line of Client A is gone, the others count.');

        $this->revenue->templateLines[self::LINE_A] = $line;
        $item = $this->pairings->items[self::CLOUD_HOST];
        unset($this->pairings->items[self::CLOUD_HOST]);
        self::assertSame(['Voice API '.$this->clientA], $recipients(), 'Cloud Host is gone, Voice API counts.');
        $this->pairings->items[self::CLOUD_HOST] = $item;
    }

    public function testAClientWithSeveralNotRebilledCostsInAMonthIsCountedForEach(): void
    {
        $this->addVoiceApi();
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->links->set(self::LINE_B, self::CLOUD_HOST);
        $this->links->set(self::LINE_A2, self::VOICE_API);
        $this->pairings->pair(self::COST_1, self::CLOUD_HOST, '2026-11-01');
        $this->pairings->pair(self::COST_2, self::VOICE_API, '2026-11-02');

        $table = MarginJson::table($this->table->of('2026-10', '2026-11'));

        self::assertSame([0, 2], $this->unrebilledOf($table['rows'], 'client:'.$this->clientA));
        self::assertSame([0, 1], $this->unrebilledOf($table['rows'], 'client:'.$this->clientB));
        $a = $this->cell->of('client:'.$this->clientA, '2026-11');
        $b = $this->cell->of('client:'.$this->clientB, '2026-11');
        self::assertSame(['Cloud Host', 'Voice API'], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->itemName, $a->unrebilled));
        self::assertSame([0, 1], array_keys($a->unrebilled), 'a list');
        self::assertSame([2, 1], [$a->figures->unrebilled, $b->figures->unrebilled]);
        self::assertSame(['Cloud Host'], array_map(static fn (\Foresi\Margin\Application\UnrebilledOccurrence $occurrence): string => $occurrence->itemName, $b->unrebilled));
    }

    public function testTheLinksAndPairingsOfSeveralLinesAreReadEachByItsOwnLine(): void
    {
        $this->holdMonth('2026-11');
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->links->set(self::LINE_B, self::CLOUD_HOST);
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::HAND, null, Money::ofMinor(100_000, Currency::CZK)), new RevenueLine(self::HAND2, null, Money::ofMinor(5_000, Currency::CZK))]);
        $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11-01');
        $this->manual->pair(self::HAND2, self::CLOUD_HOST, '2026-11-01');

        self::assertSame([], $this->links->ofLines([]));
        self::assertSame([], $this->manual->ofLines([]));
        self::assertSame([self::LINE_A], array_keys($this->links->ofLines([self::LINE_A])));
        self::assertEqualsCanonicalizing([self::LINE_A, self::LINE_B], array_keys($this->links->ofLines([self::LINE_A, self::LINE_B])));
        self::assertSame([self::HAND], array_keys($this->manual->ofLines([self::HAND])));
        self::assertEqualsCanonicalizing([self::HAND, self::HAND2], array_keys($this->manual->ofLines([self::HAND, self::HAND2])));

        $this->manual->unpair(self::HAND2);
        self::assertSame([self::HAND], array_keys($this->manual->ofLines([self::HAND, self::HAND2])), 'Only the line asked for is unpaired.');
        $this->assertRefusal(MarginRefused::UNKNOWN_PAIRING, fn () => $this->manual->unpair(self::HAND2));
        self::assertSame([self::HAND], array_keys($this->manual->ofLines([self::HAND])));
    }

    public function testALinkOrPairingToAnItemThatIsGoneIsShownByItsId(): void
    {
        $this->holdMonth('2026-11');
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::HAND, null, Money::ofMinor(100_000, Currency::CZK))]);
        $this->manual->pair(self::HAND, self::CLOUD_HOST, '2026-11-01');
        unset($this->pairings->items[self::CLOUD_HOST]);

        $link = $this->links->ofLine(self::LINE_A);
        $pairing = $this->manual->ofLines([self::HAND])[self::HAND] ?? null;

        self::assertSame([self::CLOUD_HOST, null], [$link?->plannedItemName, $link?->plannedItemUrl]);
        self::assertSame([self::CLOUD_HOST, null], [$pairing?->plannedItemName, $pairing?->plannedItemUrl]);
    }

    public function testTheTrashOfOneInvoiceTakesAwayOnlyItsPairings(): void
    {
        $this->links->set(self::LINE_A, self::CLOUD_HOST);
        $this->holdMonth('2026-11');
        $this->invoice(self::I1, $this->clientA, '2026-11-20', [new RevenueLine(self::L1, self::LINE_A, Money::ofMinor(100_000, Currency::CZK))]);
        $this->invoice(self::I2, $this->clientA, '2026-11-21', [new RevenueLine(self::L2, self::LINE_A, Money::ofMinor(100_000, Currency::CZK))]);
        $this->pairOnIssue->pair(self::I1);
        $this->pairOnIssue->pair(self::I2);
        $this->em->flush();

        self::assertSame(1, new DropRebillOnTrash($this->em)->drop(self::I1));
        $this->em->flush();

        self::assertSame([self::L2], array_map(static fn (RebillPairing $pairing): string => $pairing->invoiceLineId(), $this->em->getRepository(RebillPairing::class)->findAll()));
    }

    public function testTheCostItemsOfEverySourceAreListedOnceByName(): void
    {
        $other = new FixedPairings();
        $other->items['0197a1de-0000-7000-8000-0000000000e6'] = new PlannedCostItem('0197a1de-0000-7000-8000-0000000000e6', 'dns', true, true, null);
        $other->items[self::VOICE_API] = new PlannedCostItem(self::VOICE_API, 'Voice API', true, true, null);
        $other->items[self::CLOUD_HOST] = new PlannedCostItem(self::CLOUD_HOST, 'Cloud Host', true, true, null);
        $this->pairings->items[self::VOICE_API] = new PlannedCostItem(self::VOICE_API, 'Voice API', true, true, null);

        $items = new CostPairings([$this->pairings, $other])->costItems();

        self::assertSame(['Cloud Host', 'dns', 'Voice API'], array_map(static fn (PlannedCostItem $item): string => $item->name, $items));
        self::assertSame([0, 1, 2], array_keys($items), 'a list');
    }

    public function testTheOccurrencesOfEverySourceAreListedOldestFirst(): void
    {
        $other = new FixedPairings();
        $this->pairings->pair(self::COST_NOV, self::CLOUD_HOST, '2026-11-01');
        $other->pair(self::COST_OCT, self::CLOUD_HOST, '2026-10-01');

        $days = new CostPairings([$this->pairings, $other])->occurrencesWithDocumentsFrom(self::CLOUD_HOST, new \DateTimeImmutable('2026-09-01'));

        self::assertSame(['2026-10-01', '2026-11-01'], array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $days));
        self::assertSame([0, 1], array_keys($days), 'a list');
    }

    public function testThePanelOffersNothingWithoutALineOrForAnotherSlot(): void
    {
        $panel = new RebillPanel($this->links, $this->manual, self::getContainer()->get(MarginScreen::class));

        self::assertSame([], [...$panel->items(InvoicingSlots::RECURRING_LINE_ACTIONS, [])]);
        self::assertSame([], [...$panel->items(InvoicingSlots::INVOICE_LINE_BADGES, ['lineId' => 1])]);
        self::assertSame([], [...$panel->items('invoicing.something_else', ['lineId' => self::LINE_A])]);
        self::assertSame([], [...$panel->items(InvoicingSlots::INVOICE_LINE_ACTIONS, ['lineId' => self::L1, 'kind' => 'advance'])]);
        self::assertCount(1, [...$panel->items(InvoicingSlots::INVOICE_LINE_ACTIONS, ['lineId' => self::L1, 'kind' => 'invoice'])]);
    }

    private function addVoiceApi(): void
    {
        $this->pairings->items[self::VOICE_API] = new PlannedCostItem(self::VOICE_API, 'Voice API', true, true, null);
        $this->revenue->templateLines[self::LINE_A2] = new TemplateLine(self::LINE_A2, self::TEMPLATE_A, $this->clientA, 'Voice API', '/invoices/recurring/'.self::TEMPLATE_A);
        $this->em->persist(new PlannedAllocation(Uuid::v7()->toRfc4122(), self::VOICE_API, new \DateTimeImmutable('2026-01-01'), new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client($this->clientA))]), new \DateTimeImmutable(), 'user'));
        $this->em->flush();
    }

    private function in(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param mixed $rows the rows of the table as MarginJson writes them
     *
     * @return list<mixed> the unrebilled count of each month of one row
     */
    private function unrebilledOf(mixed $rows, string $key): array
    {
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && ($row['row'] ?? null) === $key && \is_array($row['cells'])) {
                return array_values(array_map(static fn (mixed $cell): mixed => \is_array($cell) ? $cell['unrebilled'] : null, $row['cells']));
            }
        }
        self::fail('No row '.$key);
    }

    /**
     * Every day of the month belongs to the occurrence on its first day.
     */
    private function holdMonth(string $month, string $item = self::CLOUD_HOST): void
    {
        for ($day = new \DateTimeImmutable($month.'-01'); $day->format('Y-m') === $month; $day = $day->modify('+1 day')) {
            $this->pairings->holding[$item.' '.$day->format('Y-m-d')] = new \DateTimeImmutable($month.'-01');
        }
    }

    /**
     * @param list<RevenueLine> $lines
     */
    private function invoice(string $id, string $customerId, string $taxPoint, array $lines, ?\DateTimeZone $zone = null): RevenueDocument
    {
        $document = new RevenueDocument($id, 'FV-'.$id, 'invoice', $customerId, new \DateTimeImmutable($taxPoint, $zone ?? new \DateTimeZone('Europe/Prague')), Money::ofMinor(100_000, Currency::CZK), '/invoicing/'.$id, $lines);
        $this->revenue->documents[] = $document;

        return $document;
    }

    private function contact(string $name): string
    {
        $party = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($party);

        return $party->id();
    }

    private function assertRefusal(string $reason, callable $call, string $message = ''): void
    {
        try {
            $call();
        } catch (MarginRefused $refused) {
            self::assertSame($reason, $refused->reason(), $message);

            return;
        }
        self::fail('Expected '.$reason.'.');
    }
}
