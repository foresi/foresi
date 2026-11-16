<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Delivery;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Application\SealerUnavailable;
use App\Shared\Application\SystemMail;
use App\Shared\Application\SystemMailer;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Webhooks\Support\DeliveryRows;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\SendWebhook;
use App\Webhooks\Application\WebhookSecrets;
use App\Webhooks\Domain\EndpointUrl;
use App\Webhooks\Domain\EventTypes;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use App\Webhooks\Infrastructure\Delivery\SendWebhookHandler;
use App\Webhooks\Infrastructure\Delivery\WebhookDeliveries;
use App\Webhooks\Infrastructure\Http\StandardWebhookSigner;
use App\Webhooks\Infrastructure\Http\WebhookSender;
use App\Webhooks\Infrastructure\Mail\EndpointDisabledMailer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * One attempt of a delivery: claimed so that only one worker makes it, sent
 * outside any transaction, then recorded with the next attempt or the end. A
 * receiver that is gone or fails for five days is disabled and its owner told.
 */
final class SendWebhookHandlerTest extends KernelTestCase
{
    private const string NOW = '2026-11-13 10:00:00';

    private Connection $db;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private InMemoryTransport $webhooks;
    private InMemoryTransport $mail;
    private DeliveryRows $rows;
    private User $owner;
    private TenantId $tenant;
    private MockClock $clock;
    private string $sealed;
    private int $requests = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->context = $container->get(TenantContext::class);
        $fixtures = new IdentityFixtures($this->em, $this->context);
        $fixtures->rebuildSchema();
        $this->db = $this->em->getConnection();
        $this->rows = new DeliveryRows($this->db);
        $this->webhooks = $this->transport('webhooks');
        $this->mail = $this->transport('mail');
        $this->clock = new MockClock(self::NOW, 'UTC');
        $this->sealed = $container->get(WebhookSecrets::class)->seal(WebhookSecret::generate());

        $this->owner = $fixtures->user();
        $this->tenant = $fixtures->tenant('Tenant A');
        $fixtures->join($this->owner, $this->tenant, Role::Member);
        $this->context->switchTo($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->context->reset();
        parent::tearDown();
    }

    public function testSuccessFinishesSucceeded(): void
    {
        $endpoint = $this->endpoint();
        $this->db->update('webhook_endpoint', ['failing_since' => '2026-11-12 10:00:00'], ['id' => $endpoint]);
        $delivery = $this->delivery($endpoint);

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 200);

        self::assertSame(1, $this->requests);
        $row = $this->rows->delivery($delivery);
        self::assertSame(T::STATUS_SUCCEEDED, $row[T::STATUS]);
        self::assertEquals(1, $row[T::ATTEMPTS]);
        self::assertEquals(200, $row[T::LAST_STATUS_CODE]);
        self::assertNull($row[T::NEXT_ATTEMPT_AT]);
        self::assertNotNull($row[T::FINISHED_AT]);
        $attempts = $this->rows->attempts($delivery);
        self::assertCount(1, $attempts);
        self::assertEquals(200, $attempts[0][T::STATUS_CODE]);
        self::assertSame('schedule', $attempts[0][T::TRIGGER]);
        self::assertNull($this->endpointRow($endpoint)['failing_since'], 'A success ends the run of failures.');
        self::assertCount(0, $this->webhooks->getSent());
    }

    public function testFailureSchedulesTheNextAttempt(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 500);

        $row = $this->rows->delivery($delivery);
        self::assertSame(T::STATUS_PENDING, $row[T::STATUS]);
        self::assertEquals(1, $row[T::ATTEMPTS]);
        self::assertEquals(500, $row[T::LAST_STATUS_CODE]);
        self::assertSame('2026-11-13 10:00:05', $this->datetime($row[T::NEXT_ATTEMPT_AT]));
        self::assertSame('2026-11-13 10:00:00', $this->datetime($this->endpointRow($endpoint)['failing_since']));

        $sent = $this->webhooks->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame($delivery, $message->deliveryId);
        self::assertSame(AttemptTrigger::Schedule, $message->trigger);
        self::assertSame(5000, $sent[0]->last(DelayStamp::class)?->getDelay());
        self::assertSame($this->tenant->toString(), $sent[0]->last(TenantStamp::class)?->tenantId);
    }

    public function testTenthFailureFinishesFailed(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint, attempts: 9);

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 503);

        $row = $this->rows->delivery($delivery);
        self::assertSame(T::STATUS_FAILED, $row[T::STATUS]);
        self::assertEquals(10, $row[T::ATTEMPTS]);
        self::assertNull($row[T::NEXT_ATTEMPT_AT]);
        self::assertNotNull($row[T::FINISHED_AT]);
        self::assertCount(0, $this->webhooks->getSent());
        self::assertTrue((bool) $this->endpointRow($endpoint)['active'], 'Ten failures in a day are not five days.');
    }

    public function test410DisablesAndMailsTheOwner(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 410);

        $stored = $this->endpointRow($endpoint);
        self::assertFalse((bool) $stored['active']);
        self::assertSame('gone', $stored['disabled_reason']);
        self::assertSame(T::STATUS_FAILED, $this->rows->delivery($delivery)[T::STATUS]);
        self::assertCount(0, $this->webhooks->getSent());
        $this->assertMailedOwner('410');
    }

    public function testFiveDaysOfFailuresDisableAndMail(): void
    {
        $endpoint = $this->endpoint();
        $this->db->update('webhook_endpoint', ['failing_since' => '2026-11-08 10:00:00'], ['id' => $endpoint]);
        $delivery = $this->delivery($endpoint, attempts: 3);

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 500);

        $stored = $this->endpointRow($endpoint);
        self::assertFalse((bool) $stored['active']);
        self::assertSame('failing', $stored['disabled_reason']);
        self::assertSame(T::STATUS_FAILED, $this->rows->delivery($delivery)[T::STATUS]);
        self::assertCount(0, $this->webhooks->getSent());
        $this->assertMailedOwner('five days');
    }

    public function testDisabledEndpointFinishesPendingDeliveries(): void
    {
        $endpoint = $this->endpoint();
        $this->db->update('webhook_endpoint', ['active' => false, 'disabled_reason' => 'gone'], ['id' => $endpoint], ['active' => 'boolean']);
        $delivery = $this->delivery($endpoint, attempts: 2);

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 200);

        self::assertSame(0, $this->requests);
        $row = $this->rows->delivery($delivery);
        self::assertSame(T::STATUS_FAILED, $row[T::STATUS]);
        self::assertNull($row[T::NEXT_ATTEMPT_AT]);
        $attempts = $this->rows->attempts($delivery);
        self::assertCount(1, $attempts);
        self::assertSame('endpoint_disabled', $attempts[0][T::ERROR]);
        self::assertNull($attempts[0][T::STATUS_CODE]);
        self::assertCount(0, $this->webhooks->getSent());
        self::assertCount(0, $this->mail->getSent());
    }

    public function testSecondClaimDoesNothing(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);
        $message = new SendWebhook($delivery, AttemptTrigger::Schedule);

        $deliveries = self::getContainer()->get(WebhookDeliveries::class);
        $now = $this->clock->now();
        $claimed = $deliveries->claim($delivery, $now);
        self::assertNotNull($claimed, 'The first worker holds the delivery.');
        self::assertNull($deliveries->claim($delivery, $now), 'A second claim finds it taken.');
        self::assertSame('2026-11-13 10:02:00', $this->datetime($this->rows->delivery($delivery)[T::NEXT_ATTEMPT_AT]), 'The claim is a lease.');

        $this->handle($message, 200);
        self::assertSame(0, $this->requests, 'Another worker holds it: no request.');

        $this->db->update(T::DELIVERY, [T::NEXT_ATTEMPT_AT => self::NOW], [T::ID => $delivery]);
        $this->handle($message, 200);
        $this->handle($message, 200);
        self::assertSame(1, $this->requests, 'Two handlers on one message make one request.');
        self::assertCount(1, $this->rows->attempts($delivery));
    }

    public function testStaleMessageAfterManualRedeliveryIsIgnored(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint, status: T::STATUS_FAILED, attempts: 10, nextAttemptAt: null);
        $manual = new SendWebhook($delivery, AttemptTrigger::Manual, Id::generate());

        $this->handle($manual, 200);
        $this->handle($manual, 200);
        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 200);

        self::assertSame(1, $this->requests);
        $row = $this->rows->delivery($delivery);
        self::assertSame(T::STATUS_SUCCEEDED, $row[T::STATUS]);
        self::assertEquals(11, $row[T::ATTEMPTS]);
        self::assertNull($row[T::NEXT_ATTEMPT_AT]);
        $attempts = $this->rows->attempts($delivery);
        self::assertCount(1, $attempts);
        self::assertSame($manual->attemptId, $attempts[0][T::ID]);
        self::assertSame('manual', $attempts[0][T::TRIGGER]);
        self::assertEquals(200, $attempts[0][T::STATUS_CODE]);
        self::assertCount(0, $this->webhooks->getSent());
    }

    public function testManualFailureLeavesTheScheduleAlone(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint, status: T::STATUS_FAILED, attempts: 10, nextAttemptAt: null);

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Manual, Id::generate()), 500);

        self::assertSame(1, $this->requests);
        self::assertSame(T::STATUS_FAILED, $this->rows->delivery($delivery)[T::STATUS]);
        self::assertNull($this->endpointRow($endpoint)['failing_since']);
        self::assertCount(0, $this->webhooks->getSent());
    }

    public function testPingFailureDoesNotStartFailingSince(): void
    {
        $endpoint = $this->endpoint();
        $deliveries = self::getContainer()->get(WebhookDeliveries::class);
        $ping = $deliveries->addPing($this->tenant->toString(), $endpoint, Id::generate(), '{"type":"webhooks.endpoint.ping"}', $this->clock->now());
        $stored = $this->rows->delivery($ping);
        self::assertSame(T::KIND_PING, $stored[T::KIND]);
        self::assertNull($stored[T::NEXT_ATTEMPT_AT], 'A ping is never swept.');
        $message = new SendWebhook($ping, AttemptTrigger::Ping, Id::generate());

        $this->handle($message, 500);
        $this->handle($message, 500);

        self::assertSame(1, $this->requests);
        self::assertNull($this->endpointRow($endpoint)['failing_since']);
        self::assertTrue((bool) $this->endpointRow($endpoint)['active']);
        $row = $this->rows->delivery($ping);
        self::assertSame(T::STATUS_FAILED, $row[T::STATUS]);
        self::assertEquals(1, $row[T::ATTEMPTS]);
        $attempts = $this->rows->attempts($ping);
        self::assertCount(1, $attempts);
        self::assertSame('ping', $attempts[0][T::TRIGGER]);
        self::assertCount(0, $this->webhooks->getSent(), 'A ping is one attempt, never retried.');
    }

    public function testAttemptStoresNoBodyAndNoSecret(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 500, 'response-body-marker');

        $attempts = $this->rows->attempts($delivery);
        self::assertCount(1, $attempts);
        $stored = json_encode([$attempts, $this->rows->delivery($delivery)], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('response-body-marker', $stored);
        self::assertStringNotContainsString('whsec_', $stored);
        self::assertStringNotContainsString($this->sealed, $stored);
        $attempt = json_encode($attempts, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('body-marker', $attempt, 'An attempt does not copy the body it sent.');
        self::assertSame([T::ID, T::TENANT_ID, T::DELIVERY_ID, T::ATTEMPTED_AT, T::DURATION_MS, T::STATUS_CODE, T::ERROR, T::TRIGGER], array_keys($attempts[0]));
    }

    public function testSecretThatCannotBeOpenedFailsTheAttemptAndKeepsTheSchedule(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);
        $message = new SendWebhook($delivery, AttemptTrigger::Schedule);

        $this->handle($message, 200, secrets: $this->unopenableSecrets());

        self::assertSame(0, $this->requests, 'Nothing is sent unsigned.');
        $row = $this->rows->delivery($delivery);
        self::assertSame(T::STATUS_PENDING, $row[T::STATUS]);
        self::assertEquals(1, $row[T::ATTEMPTS]);
        self::assertSame('2026-11-13 10:00:05', $this->datetime($row[T::NEXT_ATTEMPT_AT]), 'The schedule goes on.');
        $attempts = $this->rows->attempts($delivery);
        self::assertCount(1, $attempts);
        self::assertSame('internal', $attempts[0][T::ERROR]);
        self::assertNull($attempts[0][T::STATUS_CODE]);
        self::assertEquals(0, $attempts[0][T::DURATION_MS]);
        self::assertCount(1, $this->webhooks->getSent());

        $this->db->update(T::DELIVERY, [T::ATTEMPTS => 9, T::NEXT_ATTEMPT_AT => self::NOW], [T::ID => $delivery]);
        $this->handle($message, 200, secrets: $this->unopenableSecrets());

        $row = $this->rows->delivery($delivery);
        self::assertSame(T::STATUS_FAILED, $row[T::STATUS], 'After the tenth attempt the delivery ends.');
        self::assertNull($row[T::NEXT_ATTEMPT_AT]);
        self::assertSame(0, $this->requests);
    }

    public function testFailingOwnerMailKeepsTheAttemptAndTheDisabling(): void
    {
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);
        $container = self::getContainer();
        $mailer = new EndpointDisabledMailer(
            new class implements SystemMailer {
                public function send(SystemMail $mail): void
                {
                    throw new \RuntimeException('The template broke.');
                }

                public function isConfigured(): bool
                {
                    return true;
                }
            },
            $container->get(UserRepository::class),
            $container->get(MembershipDirectory::class),
            $container->get(TranslatorInterface::class),
            $container->get(Environment::class),
            $container->get(UrlGeneratorInterface::class),
            new NullLogger(),
        );

        $this->handle(new SendWebhook($delivery, AttemptTrigger::Schedule), 410, mailer: $mailer);

        self::assertSame(1, $this->requests);
        $stored = $this->endpointRow($endpoint);
        self::assertFalse((bool) $stored['active'], 'The disabling stands.');
        self::assertSame('gone', $stored['disabled_reason']);
        self::assertSame(T::STATUS_FAILED, $this->rows->delivery($delivery)[T::STATUS]);
        self::assertCount(1, $this->rows->attempts($delivery), 'The attempt is recorded.');
    }

    private function unopenableSecrets(): WebhookSecrets
    {
        return new class implements WebhookSecrets {
            public function seal(WebhookSecret $secret): string
            {
                throw new SealerUnavailable('No key is configured.');
            }

            public function open(string $sealed): WebhookSecret
            {
                throw new SealerUnavailable('The key that sealed one of the webhook secrets is not configured.');
            }

            public function isConfigured(): bool
            {
                return false;
            }
        };
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        return $transport;
    }

    private function handle(SendWebhook $message, int $status, string $body = '', ?WebhookSecrets $secrets = null, ?EndpointDisabledMailer $mailer = null): void
    {
        $client = new MockHttpClient(function () use ($status, $body): MockResponse {
            ++$this->requests;

            return new MockResponse($body, ['http_code' => $status]);
        });
        $container = self::getContainer();
        $handler = new SendWebhookHandler(
            $container->get(WebhookDeliveries::class),
            $this->em,
            $secrets ?? $container->get(WebhookSecrets::class),
            new WebhookSender($client, new StandardWebhookSigner(), $this->clock),
            $mailer ?? $container->get(EndpointDisabledMailer::class),
            $container->get(MessageBusInterface::class),
            $this->clock,
            new NullLogger(),
            $this->context,
            new Randomizer(new Mt19937(3)),
        );
        $handler($message);
        $this->em->clear();
    }

    private function endpoint(): string
    {
        $endpoint = WebhookEndpoint::create(Id::generate(), new EndpointUrl('https://hooks.example.com/receive'), null, EventTypes::of(['invoicing.*']), 1, $this->sealed, $this->owner->id(), new \DateTimeImmutable('2026-11-01 10:00:00'));
        $this->em->persist($endpoint);
        $this->em->flush();
        $this->em->clear();

        return $endpoint->id();
    }

    private function delivery(string $endpoint, string $status = T::STATUS_PENDING, int $attempts = 0, ?string $nextAttemptAt = self::NOW): string
    {
        return $this->rows->insert($this->tenant->toString(), $endpoint, $status, $attempts, $nextAttemptAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function endpointRow(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT active, disabled_reason, failing_since FROM webhook_endpoint WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function datetime(mixed $value): string
    {
        self::assertIsString($value);

        return new \DateTimeImmutable($value)->format('Y-m-d H:i:s');
    }

    private function assertMailedOwner(string $reason): void
    {
        $sent = $this->mail->getSent();
        self::assertCount(1, $sent);
        $mail = $sent[0]->getMessage();
        self::assertInstanceOf(SystemMail::class, $mail);
        self::assertSame($this->owner->email(), $mail->to);
        self::assertStringContainsString('hooks.example.com', $mail->subject);
        self::assertStringContainsString($reason, $mail->text);
        self::assertStringContainsString('/settings/webhooks/', $mail->text);
        self::assertStringNotContainsString('/receive', $mail->text, 'The mail names the host, not the whole address.');
        foreach ([$mail->text, (string) $mail->html, $mail->subject] as $part) {
            self::assertStringNotContainsString('whsec_', $part);
            self::assertStringNotContainsString($this->sealed, $part);
        }
    }
}
