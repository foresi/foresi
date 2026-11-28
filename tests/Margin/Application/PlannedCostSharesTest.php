<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\CostPairings;
use Foresi\Margin\Application\CostShareCalculation;
use Foresi\Margin\Application\CostSources;
use Foresi\Margin\Application\KnownGaps;
use Foresi\Margin\Application\MappingVersions;
use Foresi\Margin\Application\MarginAccess;
use Foresi\Margin\Application\OccurrenceFill;
use Foresi\Margin\Application\PlannedAllocationsView;
use Foresi\Margin\Application\PlannedCostItem;
use Foresi\Margin\Application\SetPlannedAllocationInput;
use Foresi\Margin\Application\SetSupplierDefaultInput;
use Foresi\Margin\Application\SupplierDefaultsView;
use Foresi\Margin\Application\SupplierDefaultVersionView;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Tests\Margin\Support\FixedCostSource;
use Foresi\Tests\Margin\Support\FixedPairings;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * The shares that come from a planned item's mapping and a supplier's
 * default, and the precedence among all mappings, on a real database with
 * documents and pairings the test names, so neither Purchasing nor Cash
 * flow is needed. Today is 10 December 2026 in Prague.
 */
final class PlannedCostSharesTest extends KernelTestCase
{
    private const string VOICE_API_ITEM = '0197a1de-0000-7000-8000-0000000000e1';
    private const string CLOUD_HOST_ITEM = '0197a1de-0000-7000-8000-0000000000e2';
    private const string DOC_1 = '0197a1de-0000-7000-8000-0000000000d1';
    private const string DOC_2 = '0197a1de-0000-7000-8000-0000000000d2';
    private const string DOC_3 = '0197a1de-0000-7000-8000-0000000000d3';

    private EntityManagerInterface $em;
    private FixedCostSource $source;
    private FixedPairings $pairings;
    private CostShareCalculation $calculation;
    private AllocationWrites $writes;
    private AllocationReads $reads;
    private string $clientA;
    private string $clientB;
    private string $cloudHost;
    private string $voiceApi;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        InstanceZone::set($container);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->clientA = $this->contact('Client A');
        $this->clientB = $this->contact('Client B');
        $this->cloudHost = $this->contact('Cloud Host');
        $this->voiceApi = $this->contact('Voice API');
        $user = new User('owner@example.com', 'hash', 'Olga Owner');
        $this->em->persist($user);
        $this->em->persist(new Membership($user, Role::Owner));
        $this->em->flush();
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));

        $this->source = new FixedCostSource();
        $this->pairings = new FixedPairings();
        $this->pairings->items[self::VOICE_API_ITEM] = new PlannedCostItem(self::VOICE_API_ITEM, 'Voice API', true, true, $this->voiceApi);
        $this->pairings->items[self::CLOUD_HOST_ITEM] = new PlannedCostItem(self::CLOUD_HOST_ITEM, 'Cloud Host', true, true, $this->cloudHost);
        $sources = new CostSources([$this->source]);
        $pairings = new CostPairings([$this->pairings]);
        $clock = $container->get(InstanceClock::class);
        $this->calculation = new CostShareCalculation($this->em, $sources, new ShareSplitter(), $clock, $pairings, new MappingVersions($this->em), new OccurrenceFill(new ShareSplitter()));
        $this->reads = new AllocationReads($container->get(MarginAccess::class), $sources, new KnownGaps($sources), $this->em, $container->get(PartyDirectory::class), $pairings, $clock);
        $this->writes = new AllocationWrites(
            $container->get(MarginAccess::class),
            $sources,
            $this->calculation,
            $this->reads,
            $this->em,
            $container->get(PartyDirectory::class),
            $container->get(AuditActor::class),
            $container->get(ClockInterface::class),
            $container->get(TransactionalWork::class),
            $pairings,
        );
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAPairedDocumentInheritsTheItemMapping(): void
    {
        $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientA), '2026-11');
        // 22 USD at 23.10 CZK.
        $document = $this->document(self::DOC_1, $this->voiceApi, '2026-11-12', [$this->line('line-1', 50_820)]);
        $this->pairings->pair($document, self::VOICE_API_ITEM, '2026-11-12');

        $this->recalculate($document);

        self::assertSame([self::DOC_1.' line-1 '.$this->clientA.' 50820 planned_item 2026-11-01 '.self::VOICE_API_ITEM.' 2026-11-12'], $this->shares());
    }

    public function testALineMappingBeatsTheItemMapping(): void
    {
        $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientA), '2026-11');
        $document = $this->document(self::DOC_1, $this->voiceApi, '2026-11-12', [$this->line('line-1', 50_820), $this->line('line-2', 10_000)]);
        $this->pairings->pair($document, self::VOICE_API_ITEM, '2026-11-12');
        $this->em->persist(new LineAllocation(Uuid::v7()->toRfc4122(), $document, 'line-2', $this->whole($this->clientB), new \DateTimeImmutable(), 'user'));
        $this->em->flush();

        $this->recalculate($document);

        self::assertSame([
            self::DOC_1.' line-1 '.$this->clientA.' 50820 planned_item 2026-11-01 '.self::VOICE_API_ITEM.' 2026-11-12',
            self::DOC_1.' line-2 '.$this->clientB.' 10000 line 2026-11-01 - -',
        ], $this->shares());
    }

    public function testAMappingChangeLeavesEarlierMonthsAlone(): void
    {
        $november = $this->document(self::DOC_1, $this->cloudHost, '2026-11-05', [$this->line('line-1', 100_000)]);
        $december = $this->document(self::DOC_2, $this->cloudHost, '2026-12-05', [$this->line('line-1', 100_000)]);
        $this->pairings->pair($november, self::CLOUD_HOST_ITEM, '2026-11-05');
        $this->pairings->pair($december, self::CLOUD_HOST_ITEM, '2026-12-05');
        $this->writes->setPlanned(self::CLOUD_HOST_ITEM, new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client($this->clientA), '30'),
            new AllocationShare(Recipient::client($this->clientB), '50'),
            new AllocationShare(Recipient::own(), '20'),
        ]), '2026-11');
        $novemberShares = $this->shareIds($november);

        $view = $this->writes->setPlanned(self::CLOUD_HOST_ITEM, new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client($this->clientA), '50'),
            new AllocationShare(Recipient::own(), '50'),
        ]), '2026-12');

        self::assertSame([
            self::DOC_1.' line-1 '.$this->clientA.' 30000 planned_item 2026-11-01 '.self::CLOUD_HOST_ITEM.' 2026-11-05',
            self::DOC_1.' line-1 '.$this->clientB.' 50000 planned_item 2026-11-01 '.self::CLOUD_HOST_ITEM.' 2026-11-05',
            self::DOC_1.' line-1 own 20000 planned_item 2026-11-01 '.self::CLOUD_HOST_ITEM.' 2026-11-05',
            self::DOC_2.' line-1 '.$this->clientA.' 50000 planned_item 2026-12-01 '.self::CLOUD_HOST_ITEM.' 2026-12-05',
            self::DOC_2.' line-1 own 50000 planned_item 2026-12-01 '.self::CLOUD_HOST_ITEM.' 2026-12-05',
        ], $this->shares());
        self::assertSame($novemberShares, $this->shareIds($november), 'The November shares were not counted again.');
        self::assertSame(['2026-12', '2026-11'], array_map(static fn (\Foresi\Margin\Application\PlannedAllocationVersionView $version): string => $version->fromMonth, $view->versions));
        self::assertSame([true, false], array_map(static fn (\Foresi\Margin\Application\PlannedAllocationVersionView $version): bool => $version->current, $view->versions));
    }

    public function testFixedAmountPerOccurrenceIsFilledInDateOrder(): void
    {
        $this->writes->setPlanned(self::CLOUD_HOST_ITEM, $this->fixed600(), '2026-05');
        // The later document has the lower id, so the order is that of the dates.
        $later = $this->document(self::DOC_1, $this->cloudHost, '2026-05-20', [$this->line('line-1', 15_750)]);
        $earlier = $this->document(self::DOC_2, $this->cloudHost, '2026-05-03', [$this->line('line-1', 67_200)]);
        $this->pairings->pair($later, self::CLOUD_HOST_ITEM, '2026-05-01');
        $this->pairings->pair($earlier, self::CLOUD_HOST_ITEM, '2026-05-01');

        $this->recalculate($later);

        self::assertSame([
            self::DOC_1.' line-1 own 15750 planned_item 2026-05-01 '.self::CLOUD_HOST_ITEM.' 2026-05-01',
            self::DOC_2.' line-1 '.$this->clientA.' 60000 planned_item 2026-05-01 '.self::CLOUD_HOST_ITEM.' 2026-05-01',
            self::DOC_2.' line-1 own 7200 planned_item 2026-05-01 '.self::CLOUD_HOST_ITEM.' 2026-05-01',
        ], $this->shares(), 'Recalculating one document of the occurrence counted the other too.');
    }

    public function testUnpairingRecalculatesTheWholeOccurrence(): void
    {
        $this->writes->setPlanned(self::CLOUD_HOST_ITEM, $this->fixed600(), '2026-05');
        $earlier = $this->document(self::DOC_1, $this->cloudHost, '2026-05-03', [$this->line('line-1', 67_200)]);
        $later = $this->document(self::DOC_2, $this->cloudHost, '2026-05-20', [$this->line('line-1', 15_750)]);
        $this->pairings->pair($earlier, self::CLOUD_HOST_ITEM, '2026-05-01');
        $this->pairings->pair($later, self::CLOUD_HOST_ITEM, '2026-05-01');
        $this->recalculate($earlier);

        $this->pairings->unpair($earlier);
        $this->recalculate($earlier);

        self::assertSame([
            self::DOC_1.' line-1 - 67200 unassigned 2026-05-01 - -',
            self::DOC_2.' line-1 '.$this->clientA.' 15750 planned_item 2026-05-01 '.self::CLOUD_HOST_ITEM.' 2026-05-01',
        ], $this->shares(), 'The document left in the occurrence now fills the fixed amount.');
    }

    public function testTheSupplierDefaultAppliesToUnpairedUnmappedDocuments(): void
    {
        $unmapped = $this->document(self::DOC_1, $this->cloudHost, '2026-12-03', [$this->line('line-1', 10_000)]);
        $mapped = $this->document(self::DOC_2, $this->cloudHost, '2026-12-04', [$this->line('line-1', 20_000)]);
        $this->em->persist(new LineAllocation(Uuid::v7()->toRfc4122(), $mapped, 'line-1', $this->whole($this->clientB), new \DateTimeImmutable(), 'user'));
        $this->em->flush();

        $view = $this->writes->setSupplierDefault($this->cloudHost, Recipient::client($this->clientA), '2026-12');

        self::assertSame([
            self::DOC_1.' line-1 '.$this->clientA.' 10000 supplier 2026-12-01 - -',
            self::DOC_2.' line-1 '.$this->clientB.' 20000 line 2026-12-01 - -',
        ], $this->shares());
        self::assertSame('Cloud Host', $view->supplierName);
        self::assertSame([['2026-12', $this->clientA, 'Client A', true]], array_map(static fn (SupplierDefaultVersionView $version): array => [$version->fromMonth, $version->recipient, $version->recipientName, $version->current], $view->versions));
        self::assertEquals($view->versions, $this->reads->supplierDefault($this->cloudHost)->versions);
        self::assertSame(['supplier'], array_map(static fn (\Foresi\Margin\Application\ShareView $share): string => $share->source, $this->reads->document($unmapped)->lines[0]->shares));
    }

    public function testASupplierDefaultLeavesEarlierMonthsAlone(): void
    {
        $november = $this->document(self::DOC_1, $this->cloudHost, '2026-11-20', [$this->line('line-1', 10_000)]);
        $this->document(self::DOC_2, $this->cloudHost, '2026-12-03', [$this->line('line-1', 20_000)]);
        $this->recalculate($november);
        $novemberShares = $this->shareIds($november);

        $this->writes->setSupplierDefault($this->cloudHost, Recipient::own(), '2026-12');

        self::assertSame([
            self::DOC_1.' line-1 - 10000 unassigned 2026-11-01 - -',
            self::DOC_2.' line-1 own 20000 supplier 2026-12-01 - -',
        ], $this->shares());
        self::assertSame($novemberShares, $this->shareIds($november));
    }

    public function testASupplierDefaultReachesADocumentPairedIntoItsMonth(): void
    {
        // Dated 30 November, paired with the occurrence of 1 December: its cost is December's.
        $document = $this->document(self::DOC_1, $this->cloudHost, '2026-11-30', [$this->line('line-1', 10_000)]);
        $this->pairings->pair($document, self::CLOUD_HOST_ITEM, '2026-12-01');
        $this->recalculate($document);

        $this->writes->setSupplierDefault($this->cloudHost, Recipient::own(), '2026-12');

        self::assertSame([self::DOC_1.' line-1 own 10000 supplier 2026-12-01 - -'], $this->shares());
    }

    public function testASupplierDefaultReachesADocumentPairedWithAQuarterlyOccurrenceMonthsLater(): void
    {
        // The quarterly occurrence of 31 December holds the invoice of 5 October: its cost is December's.
        $document = $this->document(self::DOC_1, $this->cloudHost, '2026-10-05', [$this->line('line-1', 30_000)]);
        $this->pairings->pair($document, self::CLOUD_HOST_ITEM, '2026-12-31');
        $october = $this->document(self::DOC_2, $this->cloudHost, '2026-10-06', [$this->line('line-1', 1_000)]);
        $this->recalculate($document);
        $this->recalculate($october);
        $octoberShares = $this->shareIds($october);

        $this->writes->setSupplierDefault($this->cloudHost, Recipient::own(), '2026-12');

        self::assertSame([
            self::DOC_1.' line-1 own 30000 supplier 2026-12-01 - -',
            self::DOC_2.' line-1 - 1000 unassigned 2026-10-01 - -',
        ], $this->shares());
        self::assertSame($octoberShares, $this->shareIds($october), 'An October cost was not counted again.');
    }

    public function testAPairedMappingBeatsTheSupplierDefault(): void
    {
        $this->writes->setSupplierDefault($this->voiceApi, Recipient::own(), '2026-11');
        $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientA), '2026-11');
        $paired = $this->document(self::DOC_1, $this->voiceApi, '2026-11-12', [$this->line('line-1', 50_820)]);
        $unpaired = $this->document(self::DOC_2, $this->voiceApi, '2026-11-13', [$this->line('line-1', 1_000)]);
        $this->pairings->pair($paired, self::VOICE_API_ITEM, '2026-11-12');

        $this->recalculate($paired);
        $this->recalculate($unpaired);

        self::assertSame([
            self::DOC_1.' line-1 '.$this->clientA.' 50820 planned_item 2026-11-01 '.self::VOICE_API_ITEM.' 2026-11-12',
            self::DOC_2.' line-1 own 1000 supplier 2026-11-01 - -',
        ], $this->shares());
    }

    public function testAnEndedItemMappingFallsBackToTheSupplierDefault(): void
    {
        $this->writes->setSupplierDefault($this->voiceApi, Recipient::own(), '2026-11');
        $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientA), '2026-11');
        $december = $this->document(self::DOC_1, $this->voiceApi, '2026-12-02', [$this->line('line-1', 1_000)]);
        $this->pairings->pair($december, self::VOICE_API_ITEM, '2026-12-02');

        $view = $this->writes->setPlanned(self::VOICE_API_ITEM, null, '2026-12');

        self::assertSame([self::DOC_1.' line-1 own 1000 supplier 2026-12-01 - -'], $this->shares());
        self::assertNull($view->versions[0]->mapping);
        self::assertSame('whole', $view->versions[1]->mapping?->kind);
    }

    public function testAMappingOnAnItemWithoutACounterpartyIsRefused(): void
    {
        $item = '0197a1de-0000-7000-8000-0000000000e3';
        $this->pairings->items[$item] = new PlannedCostItem($item, 'Office rent', true, true, null);

        $this->assertRefused('counterparty_required', fn (): PlannedAllocationsView => $this->writes->setPlanned($item, $this->whole($this->clientA), '2026-12'));
    }

    public function testWhatAPlannedOrSupplierMappingRefuses(): void
    {
        $income = '0197a1de-0000-7000-8000-0000000000e4';
        $once = '0197a1de-0000-7000-8000-0000000000e5';
        $this->pairings->items[$income] = new PlannedCostItem($income, 'Retainer', false, true, $this->clientA);
        $this->pairings->items[$once] = new PlannedCostItem($once, 'Laptop', true, false, $this->cloudHost);
        $whole = $this->whole($this->clientA);

        $this->assertRefused('not_an_expense', fn (): PlannedAllocationsView => $this->writes->setPlanned($income, $whole, '2026-12'));
        $this->assertRefused('not_recurring', fn (): PlannedAllocationsView => $this->writes->setPlanned($once, $whole, '2026-12'));
        $this->assertRefused('unknown_planned_item', fn (): PlannedAllocationsView => $this->writes->setPlanned('0197a1de-0000-7000-8000-0000000000ff', $whole, '2026-12'));
        $this->assertRefused('invalid_month', fn (): PlannedAllocationsView => $this->writes->setPlanned(self::VOICE_API_ITEM, $whole, '2026-13'));
        $this->assertRefused('invalid_month', fn (): SupplierDefaultsView => $this->writes->setSupplierDefault($this->cloudHost, Recipient::own(), '2026-1'));
        $this->assertRefused('unknown_contact', fn (): PlannedAllocationsView => $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole('0197a1de-0000-7000-8000-0000000000fe'), '2026-12'));
        $this->assertRefused('unknown_supplier', fn (): SupplierDefaultsView => $this->writes->setSupplierDefault('0197a1de-0000-7000-8000-0000000000fd', Recipient::own(), '2026-12'));
        $this->assertRefused('unknown_contact', fn (): SupplierDefaultsView => $this->writes->setSupplierDefault($this->cloudHost, Recipient::client('0197a1de-0000-7000-8000-0000000000fe'), '2026-12'));
    }

    public function testSettingAMonthAgainReplacesItsVersion(): void
    {
        $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientA), '2026-12');
        $view = $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientB), '2026-12');

        self::assertCount(1, $view->versions);
        self::assertSame($this->clientB, $view->versions[0]->mapping?->terms[0]->recipient);
    }

    public function testADocumentPairedWithAnOccurrenceFallsInTheOccurrencesMonth(): void
    {
        $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientA), '2026-11');
        $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientB), '2026-12');
        // The tax point is in December, the occurrence it is paired with on 30 November.
        $document = $this->document(self::DOC_1, $this->voiceApi, '2026-12-01', [$this->line('line-1', 1_000)]);
        $this->pairings->pair($document, self::VOICE_API_ITEM, '2026-11-30');

        $this->recalculate($document);

        self::assertSame([self::DOC_1.' line-1 '.$this->clientA.' 1000 planned_item 2026-11-01 '.self::VOICE_API_ITEM.' 2026-11-30'], $this->shares());
    }

    public function testAnUnpairedDocumentLateOnTheLastDayInUtcFallsInTheNextMonthOfTheInstance(): void
    {
        $this->writes->setSupplierDefault($this->cloudHost, Recipient::client($this->clientA), '2026-11');
        $this->writes->setSupplierDefault($this->cloudHost, Recipient::client($this->clientB), '2026-12');
        // 23:30 UTC on 30 November is 00:30 on 1 December in Prague.
        $document = self::DOC_1;
        $this->source->documents[$document] = new CostDocument($document, $this->cloudHost, new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC')), [$this->line('line-1', 1_000)], false, 'EN-1 Cloud Host', '/received-documents/d1');

        $this->recalculate($document);

        self::assertSame([self::DOC_1.' line-1 '.$this->clientB.' 1000 supplier 2026-12-01 - -'], $this->shares());
    }

    public function testWithoutCashFlowNoDocumentIsPairedAndNoItemIsKnown(): void
    {
        $pairings = new CostPairings();

        self::assertNull($pairings->of(self::DOC_1));
        self::assertNull($pairings->item(self::VOICE_API_ITEM));
        self::assertSame([], $pairings->documentsOfOccurrence(self::VOICE_API_ITEM, new \DateTimeImmutable('2026-11-01')));
        self::assertSame([], $pairings->documentsOfItemFrom(self::VOICE_API_ITEM, new \DateTimeImmutable('2026-11-01')));
    }

    public function testAGuestIsRefusedBeforeAnythingIsReadOrChecked(): void
    {
        $guest = new User('guest@example.com', 'hash', 'Gil Guest');
        $this->em->persist($guest);
        $this->em->persist(new Membership($guest, Role::Guest, $this->em->find(Party::class, $this->clientA)));
        $this->em->flush();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($guest->id(), $guest->email(), 'hash'), 'main', ['ROLE_USER']));

        // Each call would be refused for something else too: forbidden comes first.
        $this->assertRefused('forbidden', fn (): PlannedAllocationsView => $this->reads->planned('0197a1de-0000-7000-8000-0000000000ff'));
        $this->assertRefused('forbidden', fn (): SupplierDefaultsView => $this->reads->supplierDefault('not-an-id'));
        $this->assertRefused('forbidden', fn (): PlannedAllocationsView => $this->writes->setPlanned(self::VOICE_API_ITEM, null, '2026-13'));
        $this->assertRefused('forbidden', fn (): PlannedAllocationsView => $this->writes->setPlannedAllocation(new SetPlannedAllocationInput(self::VOICE_API_ITEM, ['kind' => 5], '2026-12')));
        $this->assertRefused('forbidden', fn (): SupplierDefaultsView => $this->writes->setSupplierDefault($this->cloudHost, null, '2026-13'));
        $this->assertRefused('forbidden', fn (): SupplierDefaultsView => $this->writes->setSupplierDefaultRecipient(new SetSupplierDefaultInput($this->cloudHost, 'not-a-recipient', '2026-12')));
        self::assertSame([], $this->em->getRepository(PlannedAllocation::class)->findAll());
    }

    public function testTheVersionsOfAMonthBelongToTheirItemOrSupplier(): void
    {
        $this->writes->setPlanned(self::VOICE_API_ITEM, $this->whole($this->clientA), '2026-12');
        $this->writes->setPlanned(self::CLOUD_HOST_ITEM, $this->whole($this->clientB), '2026-12');
        $this->writes->setSupplierDefault($this->voiceApi, Recipient::own(), '2026-12');
        $this->writes->setSupplierDefault($this->cloudHost, Recipient::client($this->clientA), '2026-12');

        $voice = $this->reads->planned(self::VOICE_API_ITEM)->versions;
        self::assertCount(1, $voice);
        self::assertSame($this->clientA, $voice[0]->mapping?->terms[0]->recipient);
        $cloud = $this->reads->planned(self::CLOUD_HOST_ITEM)->versions;
        self::assertCount(1, $cloud);
        self::assertSame($this->clientB, $cloud[0]->mapping?->terms[0]->recipient);
        self::assertSame(['own'], array_map(static fn (SupplierDefaultVersionView $version): ?string => $version->recipient, $this->reads->supplierDefault($this->voiceApi)->versions));
        self::assertSame([$this->clientA], array_map(static fn (SupplierDefaultVersionView $version): ?string => $version->recipient, $this->reads->supplierDefault($this->cloudHost)->versions));
    }

    public function testADocumentMovedToAnotherOccurrenceCountsBothOccurrencesAgain(): void
    {
        $this->writes->setPlanned(self::CLOUD_HOST_ITEM, $this->fixed600(), '2026-05');
        $moved = $this->document(self::DOC_1, $this->cloudHost, '2026-05-03', [$this->line('line-1', 67_200)]);
        $stays = $this->document(self::DOC_2, $this->cloudHost, '2026-05-20', [$this->line('line-1', 15_750)]);
        $june = $this->document(self::DOC_3, $this->cloudHost, '2026-06-10', [$this->line('line-1', 15_750)]);
        $this->pairings->pair($moved, self::CLOUD_HOST_ITEM, '2026-05-01');
        $this->pairings->pair($stays, self::CLOUD_HOST_ITEM, '2026-05-01');
        $this->pairings->pair($june, self::CLOUD_HOST_ITEM, '2026-06-01');
        $this->recalculate($moved);
        $this->recalculate($june);

        $this->pairings->pair($moved, self::CLOUD_HOST_ITEM, '2026-06-01');
        $this->recalculate($moved);

        self::assertSame([
            self::DOC_1.' line-1 '.$this->clientA.' 60000 planned_item 2026-06-01 '.self::CLOUD_HOST_ITEM.' 2026-06-01',
            self::DOC_1.' line-1 own 7200 planned_item 2026-06-01 '.self::CLOUD_HOST_ITEM.' 2026-06-01',
            self::DOC_2.' line-1 '.$this->clientA.' 15750 planned_item 2026-05-01 '.self::CLOUD_HOST_ITEM.' 2026-05-01',
            self::DOC_3.' line-1 own 15750 planned_item 2026-06-01 '.self::CLOUD_HOST_ITEM.' 2026-06-01',
        ], $this->shares(), 'The occurrence it left and the one it joined were both counted again.');
    }

    public function testATrashedDocumentOrAMappedLineFillsNoFixedAmount(): void
    {
        $this->writes->setPlanned(self::CLOUD_HOST_ITEM, $this->fixed600(), '2026-05');
        $this->source->documents[self::DOC_1] = new CostDocument(self::DOC_1, $this->cloudHost, new \DateTimeImmutable('2026-05-01', new \DateTimeZone('Europe/Prague')), [$this->line('line-1', 67_200)], true, 'EN-d1', '/received-documents/d1');
        $mapped = $this->document(self::DOC_2, $this->cloudHost, '2026-05-02', [$this->line('line-1', 67_200), $this->line('line-2', 0)]);
        $last = $this->document(self::DOC_3, $this->cloudHost, '2026-05-20', [$this->line('line-1', 15_750)]);
        foreach ([self::DOC_1, $mapped, $last] as $id) {
            $this->pairings->pair($id, self::CLOUD_HOST_ITEM, '2026-05-01');
        }
        $this->em->persist(new LineAllocation(Uuid::v7()->toRfc4122(), $mapped, 'line-1', $this->whole($this->clientB), new \DateTimeImmutable(), 'user'));
        $this->em->flush();

        $this->recalculate($last);

        self::assertSame([
            self::DOC_2.' line-1 '.$this->clientB.' 67200 line 2026-05-01 - -',
            self::DOC_3.' line-1 '.$this->clientA.' 15750 planned_item 2026-05-01 '.self::CLOUD_HOST_ITEM.' 2026-05-01',
        ], $this->shares());
    }

    public function testThePairingsOfEverySourceAreListedOnce(): void
    {
        $other = new FixedPairings();
        $this->pairings->pair(self::DOC_1, self::CLOUD_HOST_ITEM, '2026-05-01');
        $this->pairings->pair(self::DOC_2, self::CLOUD_HOST_ITEM, '2026-05-01');
        $other->pair(self::DOC_2, self::CLOUD_HOST_ITEM, '2026-05-01');
        $other->pair(self::DOC_3, self::CLOUD_HOST_ITEM, '2026-05-01');
        $pairings = new CostPairings([$this->pairings, $other]);

        self::assertSame([self::DOC_1, self::DOC_2, self::DOC_3], $pairings->documentsOfOccurrence(self::CLOUD_HOST_ITEM, new \DateTimeImmutable('2026-05-01')));
        self::assertSame([self::DOC_1, self::DOC_2, self::DOC_3], $pairings->documentsOfItemFrom(self::CLOUD_HOST_ITEM, new \DateTimeImmutable('2026-05-01')));
    }

    public function testTheShareThatTakesTheRestMayComeFirst(): void
    {
        $allocation = new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::own(), rest: true),
            new AllocationShare(Recipient::client($this->clientA), amountCzk: Money::ofMinor(60_000, Currency::of('CZK'))),
        ]);
        $document = new CostDocument(self::DOC_1, $this->cloudHost, new \DateTimeImmutable('2026-05-03'), [$this->line('line-1', 67_200)], false, 'EN-d1', '/received-documents/d1');

        $parts = new OccurrenceFill(new ShareSplitter())->split($allocation, [[$document, $document->lines]]);

        self::assertSame([['own', 7_200], [$this->clientA, 60_000]], array_map(static fn (array $part): array => [$part[0]->toString(), $part[1]->minor()], $parts[self::DOC_1]['line-1']));
    }

    private function contact(string $name): string
    {
        $party = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($party);

        return $party->id();
    }

    /**
     * @param list<CostLine> $lines
     */
    private function document(string $id, ?string $supplierId, string $basis, array $lines): string
    {
        $this->source->documents[$id] = new CostDocument($id, $supplierId, new \DateTimeImmutable($basis, new \DateTimeZone('Europe/Prague')), $lines, false, 'EN-'.$id, '/received-documents/'.$id);

        return $id;
    }

    private function line(string $key, int $minor): CostLine
    {
        return new CostLine($key, 'Hosting', Money::ofMinor($minor, Currency::of('CZK')));
    }

    private function whole(string $contactId): Allocation
    {
        return new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client($contactId))]);
    }

    private function fixed600(): Allocation
    {
        return new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::client($this->clientA), amountCzk: Money::ofMinor(60_000, Currency::of('CZK'))),
            new AllocationShare(Recipient::own(), rest: true),
        ]);
    }

    private function recalculate(string $documentId): void
    {
        $this->calculation->recalculate($documentId);
        $this->em->flush();
        $this->em->clear();
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('Not refused: '.$reason);
        } catch (MarginRefused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }

    /**
     * @return list<string>
     */
    private function shareIds(string $documentId): array
    {
        $ids = array_map(static fn (CostShare $share): string => $share->id(), $this->em->getRepository(CostShare::class)->findBy(['documentId' => $documentId]));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string> "document line recipient amount source month item occurrence", sorted
     */
    private function shares(): array
    {
        $this->em->clear();
        $rows = array_map(static fn (CostShare $share): string => \sprintf(
            '%s %s %s %d %s %s %s %s',
            $share->documentId(),
            $share->lineKey(),
            $share->recipient()?->toString() ?? '-',
            $share->amount()->minor(),
            $share->source()->value,
            $share->month()->format('Y-m-d'),
            $share->plannedItemId() ?? '-',
            $share->occurrenceOn()?->format('Y-m-d') ?? '-',
        ), $this->em->getRepository(CostShare::class)->findAll());
        sort($rows);

        return $rows;
    }
}
