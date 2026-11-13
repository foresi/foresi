<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Fanout;

use App\Events\Application\Query\EventRow;
use App\Events\Infrastructure\Api\EventView;
use App\Events\Infrastructure\Dbal\EventLogTable;
use App\Events\Infrastructure\Dbal\EventRowReader;
use App\Events\Infrastructure\Relay\EventRelay;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Events\Support\RecordedEvents;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\SendWebhook;
use App\Webhooks\Domain\EndpointUrl;
use App\Webhooks\Domain\EventTypes;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The relay hands each numbered event to the webhooks, which store one pending
 * delivery per matching active endpoint of the event's organisation and queue
 * its first attempt, in the relay's transaction.
 */
final class WebhookFanoutTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private InMemoryTransport $webhooks;
    private RecordedEvents $events;
    private IdentityFixtures $fixtures;
    private User $owner;
    private TenantId $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->context = $container->get(TenantContext::class);
        $this->fixtures = new IdentityFixtures($this->em, $this->context);
        $this->fixtures->rebuildSchema();
        $this->db = $this->em->getConnection();
        $webhooks = $container->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $webhooks);
        $this->webhooks = $webhooks;
        $this->webhooks->reset();
        $this->events = new RecordedEvents($this->context, $this->db, $container->get(EventRecorder::class), $container->get(EventRelay::class));

        $this->owner = $this->fixtures->user();
        $this->tenant = $this->fixtures->tenant('Tenant A');
        $this->fixtures->join($this->owner, $this->tenant, Role::Member);
    }

    public function testMatchingEndpointGetsOneDelivery(): void
    {
        $endpoint = $this->endpoint(['invoicing.invoice.issued']);
        $this->endpoint(['invoicing.invoice.paid']);
        $invoice = Id::generate();

        $this->events->record($this->tenant, RecordedEvents::issued($invoice));
        $this->events->number();

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        $delivery = $deliveries[0];
        self::assertSame($endpoint, $delivery[WebhookDeliveryTable::ENDPOINT_ID]);
        self::assertSame('invoicing.invoice.issued', $delivery[WebhookDeliveryTable::EVENT_TYPE]);
        self::assertSame(WebhookDeliveryTable::STATUS_PENDING, $delivery[WebhookDeliveryTable::STATUS]);
        self::assertSame(WebhookDeliveryTable::KIND_EVENT, $delivery[WebhookDeliveryTable::KIND]);
        self::assertEquals(0, $delivery[WebhookDeliveryTable::ATTEMPTS]);
        self::assertNotNull($delivery[WebhookDeliveryTable::NEXT_ATTEMPT_AT]);
        self::assertSame($this->tenant->toString(), $delivery[WebhookDeliveryTable::TENANT_ID]);

        $expected = self::getContainer()->get(EventView::class)->of($this->row());
        unset($expected['position']);
        self::assertSame(json_encode($expected, \JSON_THROW_ON_ERROR), $delivery[WebhookDeliveryTable::BODY]);
        self::assertStringNotContainsString($this->tenant->toString(), $delivery[WebhookDeliveryTable::BODY]);

        $sent = $this->webhooks->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame($delivery[WebhookDeliveryTable::ID], $message->deliveryId);
        self::assertSame(AttemptTrigger::Schedule, $message->trigger);
        self::assertNull($message->attemptId);
        self::assertSame($this->tenant->toString(), $sent[0]->last(TenantStamp::class)?->tenantId);
    }

    public function testWildcardMatches(): void
    {
        $endpoint = $this->endpoint(['invoicing.*']);

        $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));
        $this->events->record($this->tenant, RecordedEvents::paid(Id::generate()));
        $this->events->number();

        $deliveries = $this->deliveries();
        self::assertCount(2, $deliveries);
        self::assertSame([$endpoint, $endpoint], array_column($deliveries, WebhookDeliveryTable::ENDPOINT_ID));
        self::assertCount(2, $this->webhooks->getSent());
    }

    public function testOtherVersionIsSkipped(): void
    {
        $this->endpoint(['invoicing.invoice.issued'], version: 2);

        $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([], $this->deliveries());
        self::assertCount(0, $this->webhooks->getSent());
    }

    public function testInactiveEndpointIsSkipped(): void
    {
        $this->endpoint(['invoicing.invoice.issued'], active: false);

        $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([], $this->deliveries());
        self::assertCount(0, $this->webhooks->getSent());
    }

    public function testOtherTenantsEndpointIsSkipped(): void
    {
        $other = $this->fixtures->tenant('Tenant B');
        $this->fixtures->join($this->owner, $other, Role::Owner);
        $this->endpoint(['invoicing.invoice.issued'], tenant: $other);

        $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([], $this->deliveries());
        self::assertCount(0, $this->webhooks->getSent());
    }

    public function testOwnerWhoLostAccessDisablesTheEndpoint(): void
    {
        $endpoint = $this->endpoint(['invoicing.*']);
        $this->db->executeStatement('DELETE FROM membership');

        $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([], $this->deliveries());
        self::assertCount(0, $this->webhooks->getSent());
        $stored = $this->db->fetchAssociative('SELECT active, disabled_reason FROM webhook_endpoint WHERE id = ?', [$endpoint]);
        self::assertIsArray($stored);
        self::assertFalse((bool) $stored['active']);
        self::assertSame('owner_lost_access', $stored['disabled_reason']);
    }

    public function testRelayTwiceMakesNoSecondDelivery(): void
    {
        $endpoint = $this->endpoint(['invoicing.invoice.issued']);
        $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));

        $this->events->number();
        $this->events->number();

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertCount(1, $this->webhooks->getSent());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->insert(WebhookDeliveryTable::DELIVERY, [
            WebhookDeliveryTable::ID => Id::generate(),
            WebhookDeliveryTable::TENANT_ID => $this->tenant->toString(),
            WebhookDeliveryTable::ENDPOINT_ID => $endpoint,
            WebhookDeliveryTable::EVENT_ID => $deliveries[0][WebhookDeliveryTable::EVENT_ID],
            WebhookDeliveryTable::EVENT_TYPE => 'invoicing.invoice.issued',
            WebhookDeliveryTable::BODY => '{}',
            WebhookDeliveryTable::STATUS => WebhookDeliveryTable::STATUS_PENDING,
            WebhookDeliveryTable::ATTEMPTS => 0,
            WebhookDeliveryTable::KIND => WebhookDeliveryTable::KIND_EVENT,
            WebhookDeliveryTable::CREATED_AT => '2026-11-13 10:00:00',
        ]);
    }

    /**
     * @param list<string> $types
     *
     * @return string the id of the endpoint
     */
    private function endpoint(array $types, int $version = 1, bool $active = true, ?TenantId $tenant = null): string
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');
        $this->context->switchTo($tenant ?? $this->tenant);
        try {
            $endpoint = WebhookEndpoint::create(Id::generate(), new EndpointUrl('https://hooks.example.com/x'), null, EventTypes::of($types), $version, 'sealed', $this->owner->id(), $now);
            $this->em->persist($endpoint);
            $this->em->flush();
        } finally {
            $this->context->reset();
        }
        if (!$active) {
            $this->db->update('webhook_endpoint', ['active' => false], ['id' => $endpoint->id()], ['active' => 'boolean']);
        }

        return $endpoint->id();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deliveries(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM webhook_delivery ORDER BY event_id');
    }

    private function row(): EventRow
    {
        $row = $this->db->createQueryBuilder()->select(...EventRowReader::COLUMNS)->from(EventLogTable::LOG)->fetchAssociative();
        self::assertIsArray($row);

        return EventRowReader::read($row, $this->db->getDatabasePlatform());
    }
}
