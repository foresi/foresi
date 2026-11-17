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
use App\Shared\Application\SystemMail;
use App\Shared\Domain\Id;
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
 * delivery per matching active endpoint and queue its first attempt, in the
 * relay's transaction.
 */
final class WebhookFanoutTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private InMemoryTransport $webhooks;
    private InMemoryTransport $mail;
    private RecordedEvents $events;
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $fixtures = new IdentityFixtures($this->em);
        $fixtures->rebuildSchema();
        $this->db = $this->em->getConnection();
        $webhooks = $container->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $webhooks);
        $this->webhooks = $webhooks;
        $this->webhooks->reset();
        $mail = $container->get('messenger.transport.mail');
        self::assertInstanceOf(InMemoryTransport::class, $mail);
        $this->mail = $mail;
        $this->mail->reset();
        $this->events = new RecordedEvents($this->db, $container->get(EventRecorder::class), $container->get(EventRelay::class));

        $this->owner = $fixtures->user();
        $fixtures->join($this->owner, Role::Member);
    }

    public function testMatchingEndpointGetsOneDelivery(): void
    {
        $endpoint = $this->endpoint(['invoicing.invoice.issued']);
        $this->endpoint(['invoicing.invoice.paid']);
        $invoice = Id::generate();

        $this->events->record(RecordedEvents::issued($invoice));
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

        $expected = self::getContainer()->get(EventView::class)->of($this->row());
        unset($expected['position']);
        self::assertSame(json_encode($expected, \JSON_THROW_ON_ERROR), $delivery[WebhookDeliveryTable::BODY]);

        $sent = $this->webhooks->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame($delivery[WebhookDeliveryTable::ID], $message->deliveryId);
        self::assertSame(AttemptTrigger::Schedule, $message->trigger);
        self::assertNull($message->attemptId);
    }

    public function testWildcardMatches(): void
    {
        $endpoint = $this->endpoint(['invoicing.*']);

        $this->events->record(RecordedEvents::issued(Id::generate()));
        $this->events->record(RecordedEvents::paid(Id::generate()));
        $this->events->number();

        $deliveries = $this->deliveries();
        self::assertCount(2, $deliveries);
        self::assertSame([$endpoint, $endpoint], array_column($deliveries, WebhookDeliveryTable::ENDPOINT_ID));
        self::assertCount(2, $this->webhooks->getSent());
    }

    public function testOtherVersionIsSkipped(): void
    {
        $this->endpoint(['invoicing.invoice.issued'], version: 2);

        $this->events->record(RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([], $this->deliveries());
        self::assertCount(0, $this->webhooks->getSent());
    }

    public function testInactiveEndpointIsSkipped(): void
    {
        $this->endpoint(['invoicing.invoice.issued'], active: false);

        $this->events->record(RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([], $this->deliveries());
        self::assertCount(0, $this->webhooks->getSent());
    }

    public function testOwnerWhoLostAccessDisablesTheEndpoint(): void
    {
        $endpoint = $this->endpoint(['invoicing.*']);
        $this->db->executeStatement('DELETE FROM membership');

        $this->events->record(RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([], $this->deliveries());
        self::assertCount(0, $this->webhooks->getSent());
        $stored = $this->db->fetchAssociative('SELECT active, disabled_reason FROM webhook_endpoint WHERE id = ?', [$endpoint]);
        self::assertIsArray($stored);
        self::assertFalse((bool) $stored['active']);
        self::assertSame('owner_lost_access', $stored['disabled_reason']);

        $mails = $this->mail->getSent();
        self::assertCount(1, $mails, 'The owner hears of it once.');
        $mail = $mails[0]->getMessage();
        self::assertInstanceOf(SystemMail::class, $mail);
        self::assertSame($this->owner->email(), $mail->to);
        self::assertStringContainsString('hooks.example.com', $mail->subject);
        self::assertStringContainsString('/settings/webhooks/'.$endpoint, $mail->text);
    }

    public function testMalformedEndpointIsSkippedAndTheOthersStillReceive(): void
    {
        $broken = $this->endpoint(['invoicing.*']);
        $number = $this->endpoint(['invoicing.*']);
        $endpoint = $this->endpoint(['invoicing.invoice.issued']);
        // Valid JSON that is no list, so PostgreSQL's json column takes it as well.
        $this->db->executeStatement('UPDATE webhook_endpoint SET event_types = ? WHERE id = ?', ['"invoicing.*"', $broken]);
        $this->db->executeStatement('UPDATE webhook_endpoint SET event_types = ? WHERE id = ?', ['42', $number]);

        $this->events->record(RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([$endpoint], array_column($this->deliveries(), WebhookDeliveryTable::ENDPOINT_ID));
        self::assertCount(1, $this->webhooks->getSent());
        self::assertEquals(0, $this->db->fetchOne('SELECT COUNT(*) FROM event_log WHERE position IS NULL'), 'The relay went on.');
    }

    public function testRelayTwiceMakesNoSecondDelivery(): void
    {
        $endpoint = $this->endpoint(['invoicing.invoice.issued']);
        $this->events->record(RecordedEvents::issued(Id::generate()));

        $this->events->number();
        $this->events->number();

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertCount(1, $this->webhooks->getSent());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->insert(WebhookDeliveryTable::DELIVERY, [
            WebhookDeliveryTable::ID => Id::generate(),
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
    private function endpoint(array $types, int $version = 1, bool $active = true): string
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');
        $endpoint = WebhookEndpoint::create(Id::generate(), new EndpointUrl('https://hooks.example.com/x'), null, EventTypes::of($types), $version, 'sealed', $this->owner->id(), $now);
        $this->em->persist($endpoint);
        $this->em->flush();
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
