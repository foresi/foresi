<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Dbal;

use App\Audit\Application\AuditActor;
use App\Audit\Application\AuditCorrelation;
use App\Events\Application\RelayEvents;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Dbal\DbalEventRecorder;
use App\Events\Infrastructure\Payload\EventPayloads;
use App\Party\Infrastructure\Doctrine\DoctrineTransactionalWork;
use App\Shared\Domain\Module\ModuleId;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Events\Fixtures\Valid\Contract\Event\InvoiceIssued;
use App\Tests\Events\Fixtures\Valid\Contract\Kind;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
    private DoctrineTransactionalWork $work;
    private InMemoryTransport $relay;
    private TenantId $tenant;
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
        $context = $container->get(TenantContext::class);
        $this->tenant = TenantId::generate();
        $context->set($this->tenant);
        $this->correlation = new AuditCorrelation();
        $this->listeners = new EventDispatcher();
        $relay = $container->get('messenger.transport.events');
        self::assertInstanceOf(InMemoryTransport::class, $relay);
        $this->relay = $relay;
        $this->relay->reset();
        $catalogue = EventCatalogue::scan([[ModuleId::of('invoicing'), 'App\Tests\Events\Fixtures\Valid\\', \dirname(__DIR__, 2).'/Fixtures/Valid']]);
        $this->recorder = new DbalEventRecorder(
            $this->db,
            $catalogue,
            new EventPayloads(),
            $context,
            $this->correlation,
            $container->get(AuditActor::class),
            new MockClock('2026-11-12 10:00:00', 'UTC'),
            $this->listeners,
            $container->get(MessageBusInterface::class),
        );
        $this->work = new DoctrineTransactionalWork($em, $container->get(ManagerRegistry::class), $context, $this->recorder);
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
        self::assertSame($this->tenant->toString(), $rows[0]['tenant_id']);
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
            $this->work->run(function (): void {
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
        $this->work->run(static fn () => null);

        self::assertCount(0, $this->relay->getSent());
    }

    public function testRollbackDoesNotNudge(): void
    {
        try {
            $this->work->run(function (): void {
                $this->recorder->record($this->event('a'));

                throw new \RuntimeException('Refused.');
            });
        } catch (\RuntimeException) {
        }
        // The dropped count must not leak into the next unit either.
        $this->work->run(static fn () => null);

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

    public function testSyncListenerRunsInsideTheTransaction(): void
    {
        $seen = [];
        $this->listeners->addListener(InvoiceIssued::class, function () use (&$seen): void {
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
