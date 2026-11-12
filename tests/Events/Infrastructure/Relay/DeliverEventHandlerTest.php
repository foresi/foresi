<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Relay;

use App\Events\Application\DeliverEvent;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Payload\EventPayloads;
use App\Events\Infrastructure\Relay\DeliverEventHandler;
use App\Events\Infrastructure\Relay\DeliveryWork;
use App\Events\Infrastructure\Relay\EventListeners;
use App\Shared\Application\Event\EventOutbox;
use App\Shared\Domain\Id;
use App\Shared\Domain\Module\ModuleId;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Events\Fixtures\Listeners\RecordingListener;
use App\Tests\Events\Fixtures\Valid\Contract\Event\InvoiceIssued;
use App\Tests\Events\Fixtures\Valid\Contract\Kind;
use App\Tests\Events\Support\EventRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A delivery runs its listener at most once per event, in a transaction of its
 * own that also records the consumption; a failure undoes both, so the retry
 * runs the listener again.
 */
final class DeliverEventHandlerTest extends KernelTestCase
{
    private Connection $db;
    private TenantContext $context;
    private TenantId $tenant;
    private RecordingListener $listener;
    /** @var AbstractLogger&object{messages: list<string>} */
    private AbstractLogger $logger;

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
        $this->context->set($this->tenant);
        $this->listener = new RecordingListener('invoicing.invoice.issued');
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }

    public function testRunsListenerOnce(): void
    {
        $id = Id::generate();
        EventRows::insert($this->db, $id, $this->tenant->toString(), position: 1);
        $handler = $this->handler();

        $handler(new DeliverEvent($id, 'test.listener'));
        $handler(new DeliverEvent($id, 'test.listener'));

        self::assertCount(1, $this->listener->received);
        $event = $this->listener->received[0];
        self::assertSame($id, $event->id);
        self::assertSame('invoicing.invoice.issued', $event->name);
        self::assertSame(1, $event->version);
        self::assertEquals(new InvoiceIssued($id, 1, false, null, Kind::Invoice, []), $event->payload);
        self::assertSame(1, $this->consumptions());
    }

    public function testListenerFailureRollsBackConsumption(): void
    {
        $id = Id::generate();
        EventRows::insert($this->db, $id, $this->tenant->toString(), position: 1);
        $this->listener = new RecordingListener('invoicing.invoice.issued', failures: 1);
        $handler = $this->handler();

        try {
            $handler(new DeliverEvent($id, 'test.listener'));
            self::fail('The failure of the listener must surface, so the delivery is retried.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Planned failure.', $failure->getMessage());
        }
        self::assertSame(0, $this->consumptions());

        $handler(new DeliverEvent($id, 'test.listener'));

        self::assertCount(2, $this->listener->received);
        self::assertSame(1, $this->consumptions());
    }

    public function testPrunedEventIsAcked(): void
    {
        $handler = $this->handler();

        $handler(new DeliverEvent(Id::generate(), 'test.listener'));

        self::assertSame([], $this->listener->received);
        self::assertSame(0, $this->consumptions());
        self::assertCount(1, $this->logger->messages);
    }

    public function testAListenerThatIsGoneIsAcked(): void
    {
        $id = Id::generate();
        EventRows::insert($this->db, $id, $this->tenant->toString(), position: 1);

        $this->handler()(new DeliverEvent($id, 'test.removed'));

        self::assertSame(0, $this->consumptions());
        self::assertCount(1, $this->logger->messages);
    }

    public function testAnEventOfAnotherTenantIsRefused(): void
    {
        $id = Id::generate();
        EventRows::insert($this->db, $id, TenantId::generate()->toString(), position: 1);

        $this->expectException(UnrecoverableExceptionInterface::class);

        $this->handler()(new DeliverEvent($id, 'test.listener'));
    }

    private function handler(): DeliverEventHandler
    {
        $container = self::getContainer();
        $listener = $this->listener;
        $outbox = new class implements EventOutbox {
            public function committed(): void
            {
            }

            public function rolledBack(): void
            {
            }
        };

        return new DeliverEventHandler(
            $this->db,
            new EventListeners(new ServiceLocator(['test.listener' => static fn (): RecordingListener => $listener])),
            EventCatalogue::scan([[ModuleId::of('invoicing'), 'App\Tests\Events\Fixtures\Valid\\', \dirname(__DIR__, 2).'/Fixtures/Valid']]),
            new EventPayloads(),
            new DeliveryWork($container->get(EntityManagerInterface::class), $container->get(ManagerRegistry::class), $this->context, $outbox),
            $this->context,
            new MockClock('2026-11-12 11:00:00', 'UTC'),
            $this->logger,
        );
    }

    private function consumptions(): int
    {
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM event_consumption');

        return is_numeric($count) ? (int) $count : 0;
    }
}
