<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Relay;

use App\Audit\Application\AuditActor;
use App\Audit\Application\AuditCorrelation;
use App\Events\Application\DeliverEvent;
use App\Events\Application\RelayEvents;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Dbal\DbalEventRecorder;
use App\Events\Infrastructure\Payload\EventPayloads;
use App\Events\Infrastructure\Relay\EventListeners;
use App\Events\Infrastructure\Relay\EventRelay;
use App\Events\Infrastructure\Relay\RelayEventsTask;
use App\Shared\Domain\Id;
use App\Shared\Domain\Module\ModuleId;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Events\Fixtures\Listeners\RecordingListener;
use App\Tests\Events\Fixtures\Valid\Contract\Event\InvoiceIssued;
use App\Tests\Events\Fixtures\Valid\Contract\Kind;
use App\Tests\Events\Support\EventRows;
use App\Work\Infrastructure\Doctrine\DoctrineProjects;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The relay numbers committed events in the order it sees them and enqueues
 * one delivery per async listener, both in one transaction. The fixture
 * listeners stand in for a module's own.
 */
final class EventRelayTest extends KernelTestCase
{
    private Connection $db;
    private InMemoryTransport $events;
    private TenantContext $context;
    private TenantId $tenant;
    private EventListeners $listeners;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->db = $em->getConnection();
        $this->context = $container->get(TenantContext::class);
        $this->tenant = TenantId::generate();
        $events = $container->get('messenger.transport.events');
        self::assertInstanceOf(InMemoryTransport::class, $events);
        $this->events = $events;
        $this->events->reset();
        $this->listeners = new EventListeners(new ServiceLocator([
            'test.first' => static fn (): RecordingListener => new RecordingListener('invoicing.invoice.issued'),
            'test.second' => static fn (): RecordingListener => new RecordingListener('invoicing.invoice.issued'),
            'test.paid' => static fn (): RecordingListener => new RecordingListener('invoicing.invoice.paid'),
        ]));
    }

    public function testPositionsAreGaplessInCommitOrder(): void
    {
        // A is recorded first, so its id sorts first; unit 2 with B commits before unit 1 does.
        $a = Id::generate();
        $b = Id::generate();
        EventRows::insert($this->db, $b, $this->tenant->toString());
        self::assertSame(1, $this->relay()->relay());
        EventRows::insert($this->db, $a, $this->tenant->toString());
        self::assertSame(1, $this->relay()->relay());

        self::assertSame([$a => 2, $b => 1], EventRows::positions($this->db));
    }

    public function testRelayIsIdempotent(): void
    {
        EventRows::insert($this->db, Id::generate(), $this->tenant->toString());
        EventRows::insert($this->db, Id::generate(), $this->tenant->toString());
        self::assertSame(2, $this->relay()->relay());
        $this->events->reset();

        self::assertSame(0, $this->relay()->relay());
        self::assertSame([1, 2], array_values(EventRows::positions($this->db)));
        self::assertCount(0, $this->events->getSent());
    }

    public function testRelayNumbersInBatchesUntilOneComesBackShort(): void
    {
        foreach (range(1, 5) as $ignored) {
            EventRows::insert($this->db, Id::generate(), $this->tenant->toString());
        }

        self::assertSame(2, $this->relay()->relay(2));
        self::assertSame(3, $this->relay()->relayAll(2));
        self::assertSame([1, 2, 3, 4, 5], array_values(EventRows::positions($this->db)));
    }

    public function testFailedEnqueueLeavesRowsUnpositioned(): void
    {
        EventRows::insert($this->db, Id::generate(), $this->tenant->toString());
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('The queue is down.');
            }
        };

        try {
            $this->relay($bus)->relay();
            self::fail('The failed enqueue must surface.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The queue is down.', $failure->getMessage());
        }

        self::assertSame([null], array_values(EventRows::positions($this->db)));
        self::assertCount(0, $this->events->getSent());
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testOneDeliveryPerListener(): void
    {
        $id = Id::generate();
        EventRows::insert($this->db, $id, $this->tenant->toString());

        $this->relay()->relay();

        $sent = $this->events->getSent();
        self::assertCount(2, $sent);
        $listeners = [];
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(DeliverEvent::class, $message);
            self::assertSame($id, $message->eventId);
            $listeners[] = $message->listener;
            self::assertSame($this->tenant->toString(), $envelope->last(TenantStamp::class)?->tenantId);
        }
        sort($listeners);
        self::assertSame(['test.first', 'test.second'], $listeners);
    }

    public function testDeliveriesCarryTheTenantOfTheirEventNotTheActiveOne(): void
    {
        $other = TenantId::generate();
        EventRows::insert($this->db, Id::generate(), $other->toString(), name: 'invoicing.invoice.paid');
        $this->context->set($this->tenant);

        try {
            $this->relay()->relay();
        } finally {
            $this->context->reset();
        }

        $sent = $this->events->getSent();
        self::assertCount(1, $sent);
        self::assertSame($other->toString(), $sent[0]->last(TenantStamp::class)?->tenantId);
    }

    public function testSweepRelaysWhatNoNudgeAnnounced(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->context->set($this->tenant);
        $recorder = new DbalEventRecorder(
            $this->db,
            EventCatalogue::scan([[ModuleId::of('invoicing'), 'App\Tests\Events\Fixtures\Valid\\', \dirname(__DIR__, 2).'/Fixtures/Valid']]),
            new EventPayloads(),
            $this->context,
            new AuditCorrelation(),
            $container->get(AuditActor::class),
            new MockClock('2026-11-12 10:00:00', 'UTC'),
            new EventDispatcher(),
            $container->get(MessageBusInterface::class),
            new NullLogger(),
        );
        // A transaction of its own, which tells no outbox that it committed.
        new DoctrineProjects($em)->transactional(static fn () => $recorder->record(new InvoiceIssued('a', 1, false, null, Kind::Invoice, [])));
        $this->context->reset();
        foreach ($this->events->getSent() as $envelope) {
            self::assertNotInstanceOf(RelayEvents::class, $envelope->getMessage());
        }
        self::assertSame([null], array_values(EventRows::positions($this->db)));

        new RelayEventsTask($this->relay())->run();

        self::assertSame([1], array_values(EventRows::positions($this->db)));
        self::assertCount(2, $this->events->getSent());
    }

    public function testSweepRunsEveryTick(): void
    {
        $task = new RelayEventsTask($this->relay());

        self::assertSame('event-relay', $task->name());
        self::assertSame('5 minutes', $task->every());
    }

    private function relay(?MessageBusInterface $bus = null): EventRelay
    {
        $container = self::getContainer();

        return new EventRelay($this->db, $container->get(LockFactory::class), $this->listeners, $bus ?? $container->get(MessageBusInterface::class));
    }
}
