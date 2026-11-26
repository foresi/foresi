<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Audit\Application\AuditActor;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Events\Application\RelayEvents;
use Foresi\Events\Infrastructure\Catalogue\EventCatalogue;
use Foresi\Events\Infrastructure\Dbal\DbalEventRecorder;
use Foresi\Events\Infrastructure\Payload\EventPayloads;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Infrastructure\Doctrine\DoctrineUnitOfWork;
use Foresi\Tests\Events\Fixtures\Valid\Contract\Event\InvoiceIssued;
use Foresi\Tests\Events\Fixtures\Valid\Contract\Kind;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The recorder writes on the connection of the unit of work, so its rows live and
 * die with the change. The fixture events stand in for a module's own.
 */
final class DbalEventRecorderTest extends KernelTestCase
{
    private Connection $db;
    private EventDispatcher $listeners;
    private DbalEventRecorder $recorder;
    private DoctrineUnitOfWork $work;
    private InMemoryTransport $relay;
    private AuditCorrelation $correlation;

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
        $this->correlation = new AuditCorrelation();
        $this->listeners = new EventDispatcher();
        $relay = $container->get('messenger.transport.events');
        self::assertInstanceOf(InMemoryTransport::class, $relay);
        $this->relay = $relay;
        $this->relay->reset();
        $catalogue = EventCatalogue::scan([[ModuleId::of('invoicing'), 'Foresi\Tests\Events\Fixtures\Valid\\', \dirname(__DIR__, 2).'/Fixtures/Valid']]);
        $this->recorder = new DbalEventRecorder(
            $this->db,
            $catalogue,
            new EventPayloads(),
            $this->correlation,
            $container->get(AuditActor::class),
            new MockClock('2026-11-12 10:00:00', 'UTC'),
            $this->listeners,
            $container->get(MessageBusInterface::class),
            new NullLogger(),
        );
        $this->work = new DoctrineUnitOfWork($em, $container->get(ManagerRegistry::class), $this->recorder);
    }

    public function testRecordOutsideATransactionThrows(): void
    {
        $this->expectException(\LogicException::class);

        $this->recorder->record($this->event('a'));
    }

    public function testRecordsTheEnvelopeAndFactsInTheTransaction(): void
    {
        $this->correlation->within('work:test', fn () => $this->work->run(fn () => $this->recorder->record($this->event('a'))));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['position']);
        self::assertSame('work:test', $rows[0]['correlation_id']);
        self::assertSame('system', $rows[0]['actor_kind']);
        self::assertSame('invoicing.invoice.issued', $rows[0]['name']);
        self::assertEquals(1, $rows[0]['version']);
        self::assertSame(['invoice', 'a'], [$rows[0]['subject_type'], $rows[0]['subject_id']]);
        self::assertSame(['invoice_id' => 'a', 'line_count' => 1, 'delivered' => false, 'due_on' => null, 'kind' => 'invoice', 'line_codes' => []], json_decode(\is_string($rows[0]['data']) ? $rows[0]['data'] : '', true));
    }

    public function testRolledBackRecordLeavesNoRow(): void
    {
        try {
            $this->work->run(function (): never {
                $this->recorder->record($this->event('a'));

                throw new \RuntimeException('Refused.');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->rows());
    }

    public function testCommitNudgesTheRelayOnce(): void
    {
        $this->work->run(function (): void {
            $this->recorder->record($this->event('a'));
            $this->recorder->record($this->event('b'));
        });

        self::assertCount(1, $this->relay->getSent());
        self::assertInstanceOf(RelayEvents::class, $this->relay->getSent()[0]->getMessage());
    }

    public function testACommitThatRecordedNothingDoesNotNudge(): void
    {
        $this->work->run(static fn (): null => null);

        self::assertCount(0, $this->relay->getSent());
    }

    public function testRollbackDoesNotNudge(): void
    {
        try {
            $this->work->run(function (): never {
                $this->recorder->record($this->event('a'));

                throw new \RuntimeException('Refused.');
            });
        } catch (\RuntimeException) {
        }
        // The dropped count must not leak into the next unit either.
        $this->work->run(static fn (): null => null);

        self::assertCount(0, $this->relay->getSent());
    }

    public function testNestedUnitNudgesOnlyAtTheOutermostCommit(): void
    {
        $this->work->run(function (): void {
            $this->work->run(fn () => $this->recorder->record($this->event('a')));
            self::assertCount(0, $this->relay->getSent());
        });

        self::assertCount(1, $this->relay->getSent());
    }

    public function testAFailedNudgeNeverFailsACommittedUnit(): void
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('The queue is down.');
            }
        };
        $recorder = new DbalEventRecorder(
            $this->db,
            EventCatalogue::scan([[ModuleId::of('invoicing'), 'Foresi\Tests\Events\Fixtures\Valid\\', \dirname(__DIR__, 2).'/Fixtures/Valid']]),
            new EventPayloads(),
            $this->correlation,
            self::getContainer()->get(AuditActor::class),
            new MockClock('2026-11-12 10:00:00', 'UTC'),
            $this->listeners,
            $bus,
            new NullLogger(),
        );
        $work = new DoctrineUnitOfWork(self::getContainer()->get(EntityManagerInterface::class), self::getContainer()->get(ManagerRegistry::class), $recorder);

        $work->run(fn () => $recorder->record($this->event('a')));

        self::assertCount(1, $this->rows());
    }

    public function testSyncListenerRunsInsideTheTransaction(): void
    {
        $seen = [];
        $this->listeners->addListener(InvoiceIssued::class, function () use (&$seen): never {
            $seen[] = $this->db->isTransactionActive();

            throw new \DomainException('The listener refuses.');
        });

        try {
            $this->work->run(fn () => $this->recorder->record($this->event('a')));
        } catch (\DomainException) {
        }

        self::assertSame([true], $seen);
        self::assertSame([], $this->rows());
        self::assertCount(0, $this->relay->getSent());
    }

    private function event(string $id): InvoiceIssued
    {
        return new InvoiceIssued($id, 1, false, null, Kind::Invoice, []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM event_log ORDER BY id');
    }
}
