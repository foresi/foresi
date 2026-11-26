<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\Invoicing;

use Foresi\Events\Infrastructure\Relay\EventListeners;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Application\IssueRecurringOccurrence;
use Foresi\Invoicing\Contract\Event\DocumentKindValue;
use Foresi\Invoicing\Contract\Event\InvoiceIssued;
use Foresi\Invoicing\Contract\Event\InvoiceRestored;
use Foresi\Invoicing\Contract\Event\InvoiceTrashed;
use Foresi\Invoicing\Integration\Margin\IssuedRevenue;
use Foresi\Margin\Application\CostPairings;
use Foresi\Margin\Application\DropRebillOnTrash;
use Foresi\Margin\Application\MappingVersions;
use Foresi\Margin\Application\MarginAccess;
use Foresi\Margin\Application\PairRebillOnIssue;
use Foresi\Margin\Application\PlannedCostItem;
use Foresi\Margin\Application\RebillLinks;
use Foresi\Margin\Application\RevenueSources;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Domain\RebillPairing;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Integration\Invoicing\DropRebillOnInvoiceTrashed;
use Foresi\Margin\Integration\Invoicing\PairRebillOnInvoiceIssued;
use Foresi\Margin\Integration\Invoicing\PairRebillOnInvoiceRestored;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\RecordedEvent;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Invoicing\Application\RecurringTestCase;
use Foresi\Tests\Margin\Support\FixedPairings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * Rebilling over the real Invoicing: the lines of a recurring template, kept
 * by id when the template is revised, and the invoices issued from them.
 */
final class RebillOverInvoicingTest extends RecurringTestCase
{
    private const string ITEM = '0197a1de-0000-7000-8000-0000000000e2';

    private RevenueSources $revenue;
    private FixedPairings $pairings;
    private RebillLinks $links;
    private PairRebillOnIssue $pairOnIssue;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $user = new User('owner@example.com', 'hash', 'Olga Owner');
        $this->em->persist($user);
        $this->em->persist(new Membership($user, Role::Owner));
        $this->em->flush();
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));

        $revenue = $this->revenue = new RevenueSources([new IssuedRevenue($container->get(InvoiceStore::class), $container->get(IssuedDocumentFactory::class), $container->get(UrlGeneratorInterface::class), $this->em, $container->get(InstanceClock::class))]);
        $this->pairings = new FixedPairings();
        $this->pairings->items[self::ITEM] = new PlannedCostItem(self::ITEM, 'Cloud Host', true, true, null);
        $costPairings = new CostPairings([$this->pairings]);
        $mappings = new MappingVersions($this->em);
        $this->links = new RebillLinks($container->get(MarginAccess::class), $revenue, $costPairings, $mappings, $this->em, $container->get(InstanceClock::class), $container->get(TransactionalWork::class));
        $this->pairOnIssue = new PairRebillOnIssue($revenue, $costPairings, $mappings, $this->em);
        $this->em->persist(new PlannedAllocation(Uuid::v7()->toRfc4122(), self::ITEM, new \DateTimeImmutable('2026-01-01'), new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client($this->customer->id()))]), new \DateTimeImmutable(), 'user'));
        $this->em->flush();
    }

    public function testRevisingTheTemplateKeepsTheRebillLink(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'Hosting'), $this->line('250.00', text: 'Support')]));
        $ids = array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', array_column($this->lineRows($template->id()), 'id'));
        $this->links->set($ids[0], self::ITEM);

        $this->em->clear();
        $this->save->edit($template->id(), $this->templates->get($template->id())->version(), $this->recurringInput([$this->line(text: 'Hosting renamed'), $this->line('250.00', text: 'Support')]));
        $this->em->clear();

        $link = $this->links->ofLine($ids[0]);
        self::assertSame([self::ITEM, 'Cloud Host', []], [$link?->plannedItemId, $link?->plannedItemName, $link?->warnings], 'The line is revised in place, so its link still resolves to the template.');
        self::assertNull($this->links->ofLine($ids[1]));
    }

    public function testAnInvoiceIssuedFromTheTemplatePairsWithTheOccurrenceOfItsTaxPoint(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'Hosting'), $this->line('250.00', text: 'Support')]));
        $ids = array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', array_column($this->lineRows($template->id()), 'id'));
        $this->links->set($ids[0], self::ITEM);
        self::mockTime('2026-12-03 09:00:00');
        $invoice = self::getContainer()->get(IssueRecurringOccurrence::class)->issue($template->id(), new \DateTimeImmutable('2026-12-01'));
        self::assertNotNull($invoice);
        $lines = $this->em->getConnection()->fetchAllAssociative('SELECT id, source_line_id FROM invoice_line WHERE invoice_id = :id ORDER BY position', ['id' => $invoice->id()]);
        $document = $this->revenue->document($invoice->id());
        self::assertNotNull($document);
        $this->pairings->holding[self::ITEM.' '.$document->taxPointOn->format('Y-m-d')] = new \DateTimeImmutable('2026-12-01');
        $this->em->clear();

        $listener = new PairRebillOnInvoiceIssued($this->pairOnIssue);
        $listener(new RecordedEvent(Id::generate(), PairRebillOnInvoiceIssued::EVENT, 1, new \DateTimeImmutable(), null, new InvoiceIssued($invoice->id(), DocumentKindValue::Invoice, $document->number, Id::generate(), Id::generate(), $this->customer->id(), 'CZK', '1', '2026-12-01', null, null)));
        $this->em->flush();
        self::assertSame(1, $this->em->getRepository(RebillPairing::class)->count([]), 'the event paired the line of the issued invoice');
        $listener(new RecordedEvent(Id::generate(), PairRebillOnInvoiceIssued::EVENT, 1, new \DateTimeImmutable(), null, new InvoiceIssued($invoice->id(), DocumentKindValue::Invoice, $document->number, Id::generate(), Id::generate(), $this->customer->id(), 'CZK', '1', '2026-12-01', null, null)));
        self::assertSame(0, $this->pairOnIssue->pair($invoice->id()), 'a second delivery pairs nothing more');

        $trashed = new DropRebillOnInvoiceTrashed(new DropRebillOnTrash($this->em));
        $trashed(new RecordedEvent(Id::generate(), DropRebillOnInvoiceTrashed::EVENT, 1, new \DateTimeImmutable(), null, new InvoiceTrashed($invoice->id(), DocumentKindValue::Invoice)));
        $this->em->flush();
        self::assertSame(0, $this->em->getRepository(RebillPairing::class)->count([]), 'the trashed invoice rebills nothing');

        $restored = new PairRebillOnInvoiceRestored($this->pairOnIssue);
        $restored(new RecordedEvent(Id::generate(), PairRebillOnInvoiceRestored::EVENT, 1, new \DateTimeImmutable(), null, new InvoiceRestored($invoice->id(), DocumentKindValue::Invoice)));
        $this->em->flush();
        self::assertSame(1, $this->em->getRepository(RebillPairing::class)->count([]), 'restored, it rebills again');
        $this->em->flush();

        $pairings = $this->em->getRepository(RebillPairing::class)->findAll();
        self::assertCount(1, $pairings);
        self::assertSame([$lines[0]['id'], $invoice->id(), $this->customer->id(), '2026-12-01', false], [$pairings[0]->invoiceLineId(), $pairings[0]->invoiceId(), $pairings[0]->customerId(), $pairings[0]->occurrenceOn()->format('Y-m-d'), $pairings[0]->manual()]);
    }

    public function testTheListenerIsRegisteredForTheEventOfAnIssuedInvoice(): void
    {
        $listeners = self::getContainer()->get(EventListeners::class);
        self::assertContains(PairRebillOnInvoiceIssued::class, $listeners->idsFor(PairRebillOnInvoiceIssued::EVENT));
        self::assertContains(DropRebillOnInvoiceTrashed::class, $listeners->idsFor(DropRebillOnInvoiceTrashed::EVENT));
        self::assertContains(PairRebillOnInvoiceRestored::class, $listeners->idsFor(PairRebillOnInvoiceRestored::EVENT));
    }
}
