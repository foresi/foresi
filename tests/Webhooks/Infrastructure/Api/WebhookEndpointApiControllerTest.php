<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Api;

use App\Audit\Domain\AuditEntry;
use App\Invoicing\Infrastructure\Events\InvoicingEventAccess;
use App\Shared\Application\Event\EventReadAccess;
use App\Shared\Application\SealerUnavailable;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\SendWebhook;
use App\Webhooks\Application\WebhookSecrets;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Crypto\SodiumWebhookSecrets;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;

/**
 * Webhook endpoints managed over REST: registered with a secret shown once,
 * listed and shown without it, changed, rotated, revealed, pinged and deleted,
 * each by a token with the right scope and a person who may read every module
 * the endpoint subscribes to.
 */
final class WebhookEndpointApiControllerTest extends WebhookApiTestCase
{
    public function testCreateAnswers201WithSecretAndQueuesPing(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'description' => 'Accounting sync', 'event_types' => ['invoicing.invoice.paid', 'invoicing.*']]);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', self::ENDPOINTS.'/'.$id);
        self::assertSame('https://hooks.example.com/foresi', $this->at('data', 'url'));
        self::assertSame('Accounting sync', $this->at('data', 'description'));
        self::assertSame(['invoicing.invoice.paid', 'invoicing.*'], $this->at('data', 'event_types'));
        self::assertSame(1, $this->at('data', 'version'));
        self::assertTrue($this->at('data', 'active'));
        self::assertNull($this->at('data', 'disabled_reason'));
        self::assertSame($this->user->id(), $this->at('data', 'owner_id'));
        $secret = $this->at('data', 'secret');
        self::assertIsString($secret);
        self::assertStringStartsWith('whsec_', $secret);
        self::assertSame(32, \strlen(WebhookSecret::fromString($secret)->key()));

        $endpoint = $this->endpoint($id);
        self::assertInstanceOf(WebhookEndpoint::class, $endpoint);
        self::assertSame($this->user->id(), $endpoint->ownerId());

        $queued = $this->queued();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame(AttemptTrigger::Ping, $message->trigger);
        self::assertNotNull($message->attemptId);
        self::assertSame($this->tenant->toString(), $queued[0]->last(TenantStamp::class)?->tenantId);
        $ping = $this->rows->delivery($message->deliveryId);
        self::assertSame($id, $ping[T::ENDPOINT_ID]);
        self::assertSame(T::KIND_PING, $ping[T::KIND]);
        self::assertSame(T::STATUS_PENDING, $ping[T::STATUS]);
        self::assertSame('webhooks.endpoint.ping', $ping[T::EVENT_TYPE]);
        self::assertNull($ping[T::NEXT_ATTEMPT_AT]);
        self::assertIsString($ping[T::BODY]);
        $body = json_decode($ping[T::BODY], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($ping[T::EVENT_ID], $body['id']);
        self::assertSame('webhooks.endpoint.ping', $body['type']);
        self::assertSame(1, $body['version']);
        self::assertSame(['type' => 'webhook_endpoint', 'id' => $id, 'url' => self::ENDPOINTS.'/'.$id], $body['subject']);
        self::assertSame(['endpoint_id' => $id], $body['data']);
        self::assertArrayNotHasKey('position', $body);
        self::assertStringNotContainsString($this->tenant->toString(), $ping[T::BODY]);
    }

    public function testCreateWithReadScopeIs403(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*']], $this->secret);

        $this->assertProblem(403, 'insufficient_scope');
        self::assertSame([], $this->endpoints());
        self::assertSame([], $this->queued());
    }

    public function testHttpUrlIs400(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'http://hooks.example.com/foresi', 'event_types' => ['invoicing.*']]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'url']);
        self::assertSame([], $this->endpoints());
    }

    public function testPrivateHostIs400(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://internal.example.com/foresi', 'event_types' => ['invoicing.*']]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'url']);
        self::assertSame([], $this->endpoints());
    }

    public function testStarPatternIs400(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['*']]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'event_types']);
        self::assertSame([], $this->endpoints());
    }

    public function testUnknownTypeIs400(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.invoice.exploded']]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'event_types']);
        self::assertSame([], $this->endpoints());
    }

    public function testMissingBodyFieldIs400(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi']);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'event_types']);
    }

    public function testUnreadableModuleIs403(): void
    {
        $id = $this->stored($this->tenant);
        // The person no longer works with invoices: their access service says no.
        self::getContainer()->set(InvoicingEventAccess::class, new class implements EventReadAccess {
            public function module(): string
            {
                return 'invoicing';
            }

            public function mayRead(): bool
            {
                return false;
            }

            public function mayUserRead(string $userId): bool
            {
                return false;
            }
        });

        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/other', 'event_types' => ['invoicing.*']]);
        $this->assertProblem(403, 'forbidden');

        $this->get(self::ENDPOINTS.'/'.$id, secret: $this->writer);
        $this->assertProblem(403, 'forbidden');
        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['description' => 'Mine now']);
        $this->assertProblem(403, 'forbidden');

        $this->get(self::ENDPOINTS, secret: $this->writer);
        self::assertSame([], $this->ids());
        self::assertCount(1, $this->endpoints());
    }

    public function testListAndShowNeverContainTheSecret(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*']]);
        $id = $this->at('data', 'id');
        $secret = $this->at('data', 'secret');
        self::assertIsString($id);
        self::assertIsString($secret);
        $sealed = $this->em()->getConnection()->fetchOne('SELECT secret FROM webhook_endpoint WHERE id = ?', [$id]);
        self::assertIsString($sealed);

        $this->get(self::ENDPOINTS);
        self::assertSame([$id], $this->ids());
        $this->assertNoSecret($secret, $sealed);
        self::assertNull($this->json()['next_cursor']);

        $this->get(self::ENDPOINTS.'/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame($id, $this->at('data', 'id'));
        $this->assertNoSecret($secret, $sealed);

        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['description' => 'Changed']);
        self::assertResponseIsSuccessful();
        $this->assertNoSecret($secret, $sealed);
    }

    public function testListPagesByCursor(): void
    {
        $first = $this->created();
        $second = $this->created();
        $third = $this->created();

        self::assertSame([[$third, $second], [$first]], $this->pages(self::ENDPOINTS, 2));
    }

    public function testAuditEntryHoldsNoSecret(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*']]);
        $id = $this->at('data', 'id');
        $created = $this->at('data', 'secret');
        self::assertIsString($id);
        self::assertIsString($created);
        $this->send('POST', self::ENDPOINTS.'/'.$id.'/secret/rotate', ['keep_previous_hours' => 24]);
        self::assertResponseIsSuccessful();
        $rotated = $this->at('data', 'secret');
        self::assertIsString($rotated);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT secret, previous_secret FROM webhook_endpoint WHERE id = ?', [$id]);
        self::assertIsArray($row);

        $this->em()->clear();
        /** @var list<AuditEntry> $entries */
        $entries = $this->inTenant($this->tenant, fn (): array => $this->em()->createQuery('SELECT e FROM '.AuditEntry::class.' e WHERE e.entityClass = :class')
            ->setParameter('class', WebhookEndpoint::class)
            ->getResult());
        self::assertCount(2, $entries);
        $logged = json_encode(array_map(static fn (AuditEntry $entry): array => $entry->changes(), $entries), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('whsec_', $logged);
        foreach ([$created, $rotated, $row['secret'], $row['previous_secret']] as $value) {
            self::assertIsString($value);
            self::assertStringNotContainsString($value, $logged);
            self::assertStringNotContainsString(json_encode($value, \JSON_THROW_ON_ERROR), $logged);
        }
    }

    public function testRotateKeepsPreviousForGivenHours(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*']]);
        $id = $this->at('data', 'id');
        $old = $this->at('data', 'secret');
        self::assertIsString($id);
        self::assertIsString($old);
        $before = new \DateTimeImmutable();

        $this->send('POST', self::ENDPOINTS.'/'.$id.'/secret/rotate', ['keep_previous_hours' => 48]);

        self::assertResponseStatusCodeSame(200);
        $new = $this->at('data', 'secret');
        self::assertIsString($new);
        self::assertStringStartsWith('whsec_', $new);
        self::assertNotSame($old, $new);
        $expires = $this->at('data', 'previous_secret_expires_at');
        self::assertIsString($expires);
        $expiresAt = new \DateTimeImmutable($expires);
        self::assertGreaterThanOrEqual($before->modify('+48 hours')->getTimestamp() - 1, $expiresAt->getTimestamp());
        self::assertLessThanOrEqual(new \DateTimeImmutable()->modify('+48 hours')->getTimestamp() + 1, $expiresAt->getTimestamp());
        $endpoint = $this->endpoint($id);
        self::assertInstanceOf(WebhookEndpoint::class, $endpoint);
        $secrets = self::getContainer()->get(SodiumWebhookSecrets::class);
        self::assertSame([$new, $old], array_map(static fn (string $sealed): string => $secrets->open($sealed)->reveal(), $endpoint->signingSecrets(new \DateTimeImmutable())));
        self::assertCount(1, $endpoint->signingSecrets($expiresAt->modify('+1 second')));

        $this->send('POST', self::ENDPOINTS.'/'.$id.'/secret/rotate', ['keep_previous_hours' => 0]);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->at('data', 'previous_secret_expires_at'));
        $endpoint = $this->endpoint($id);
        self::assertInstanceOf(WebhookEndpoint::class, $endpoint);
        self::assertCount(1, $endpoint->signingSecrets(new \DateTimeImmutable()));

        $this->send('POST', self::ENDPOINTS.'/'.$id.'/secret/rotate', ['keep_previous_hours' => 169]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'keep_previous_hours']);
    }

    public function testRotateKeepsTheDefaultDayWithoutABody(): void
    {
        $id = $this->created();

        $this->send('POST', self::ENDPOINTS.'/'.$id.'/secret/rotate');

        self::assertResponseStatusCodeSame(200);
        $expires = $this->at('data', 'previous_secret_expires_at');
        self::assertIsString($expires);
        self::assertEqualsWithDelta(new \DateTimeImmutable()->modify('+24 hours')->getTimestamp(), new \DateTimeImmutable($expires)->getTimestamp(), 2);
    }

    public function testRevealNeedsWrite(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*']]);
        $id = $this->at('data', 'id');
        $secret = $this->at('data', 'secret');
        self::assertIsString($id);

        $this->get(self::ENDPOINTS.'/'.$id.'/secret');
        $this->assertProblem(403, 'insufficient_scope');

        $this->get(self::ENDPOINTS.'/'.$id.'/secret', secret: $this->writer);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($secret, $this->at('data', 'secret'));
    }

    public function testPatchDisableAndEnable(): void
    {
        $id = $this->created();

        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['active' => false]);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->at('data', 'active'));
        self::assertSame('manual', $this->at('data', 'disabled_reason'));

        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['active' => true]);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->at('data', 'active'));
        self::assertNull($this->at('data', 'disabled_reason'));
        self::assertSame([], $this->queued());
    }

    public function testPatchChangesOnlyWhatIsGiven(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'description' => 'Accounting sync', 'event_types' => ['invoicing.*']]);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['event_types' => ['invoicing.invoice.paid'], 'version' => 2]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['invoicing.invoice.paid'], $this->at('data', 'event_types'));
        self::assertSame(2, $this->at('data', 'version'));
        self::assertSame('Accounting sync', $this->at('data', 'description'));
        self::assertSame('https://hooks.example.com/foresi', $this->at('data', 'url'));

        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['url' => 'https://other.example.com/hook', 'description' => null]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('https://other.example.com/hook', $this->at('data', 'url'));
        self::assertNull($this->at('data', 'description'));

        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['url' => 'https://internal.example.com/hook']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'url']);
        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['version' => 0]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'version']);
        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['event_types' => []]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'event_types']);
        $endpoint = $this->endpoint($id);
        self::assertInstanceOf(WebhookEndpoint::class, $endpoint);
        self::assertSame('https://other.example.com/hook', $endpoint->url()->value);
        self::assertSame(2, $endpoint->version());
    }

    public function testDeleteRemovesDeliveries(): void
    {
        $id = $this->created();
        $other = $this->created();
        $delivery = $this->rows->insert($this->tenant->toString(), $id, T::STATUS_FAILED);
        $this->rows->attempt($this->tenant->toString(), $delivery, '2026-11-13 10:00:00');
        $kept = $this->rows->insert($this->tenant->toString(), $other, T::STATUS_FAILED);
        $this->rows->attempt($this->tenant->toString(), $kept, '2026-11-13 10:00:00');

        $this->send('DELETE', self::ENDPOINTS.'/'.$id, secret: $this->secret);
        $this->assertProblem(403, 'insufficient_scope');

        $this->send('DELETE', self::ENDPOINTS.'/'.$id);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertNull($this->endpoint($id));
        $db = $this->em()->getConnection();
        self::assertSame([], $db->fetchFirstColumn('SELECT id FROM webhook_delivery WHERE endpoint_id = ?', [$id]));
        self::assertSame([], $this->rows->attempts($delivery));
        self::assertCount(1, $this->rows->attempts($kept));

        $this->get(self::ENDPOINTS.'/'.$id);
        $this->assertProblem(404, 'not_found');
    }

    public function testPingAnswers202AndQueuesOneAttempt(): void
    {
        $id = $this->created();

        $this->send('POST', self::ENDPOINTS.'/'.$id.'/ping', secret: $this->secret);
        $this->assertProblem(403, 'insufficient_scope');

        $this->send('POST', self::ENDPOINTS.'/'.$id.'/ping');
        self::assertResponseStatusCodeSame(202);
        $queued = $this->queued();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame(AttemptTrigger::Ping, $message->trigger);
        self::assertSame($message->deliveryId, $this->at('data', 'delivery_id'));
        self::assertSame($message->attemptId, $this->at('data', 'attempt_id'));

        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['active' => false]);
        $this->send('POST', self::ENDPOINTS.'/'.$id.'/ping');
        $this->assertProblem(409, 'conflict');
    }

    public function testOtherTenantsEndpointIs404(): void
    {
        $foreign = $this->stored($this->foreignTenant());

        $this->get(self::ENDPOINTS.'/'.$foreign);
        $this->assertProblem(404, 'not_found');
        $this->send('PATCH', self::ENDPOINTS.'/'.$foreign, ['description' => 'Taken']);
        $this->assertProblem(404, 'not_found');
        $this->send('DELETE', self::ENDPOINTS.'/'.$foreign);
        $this->assertProblem(404, 'not_found');
        $this->get(self::ENDPOINTS.'/'.$foreign.'/secret', secret: $this->writer);
        $this->assertProblem(404, 'not_found');
        $this->send('POST', self::ENDPOINTS.'/'.$foreign.'/secret/rotate');
        $this->assertProblem(404, 'not_found');
        $this->get(self::ENDPOINTS.'/'.$foreign.'/deliveries');
        $this->assertProblem(404, 'not_found');
        $this->get(self::ENDPOINTS);
        self::assertSame([], $this->ids());
        self::assertSame([$foreign], $this->endpoints());
    }

    public function testSecretsUnavailableIs503(): void
    {
        $id = $this->stored($this->tenant);
        self::getContainer()->set(SodiumWebhookSecrets::class, new class implements WebhookSecrets {
            public function seal(WebhookSecret $secret): string
            {
                throw new SealerUnavailable('No key.');
            }

            public function open(string $sealed): WebhookSecret
            {
                throw new SealerUnavailable('No key.');
            }

            public function isConfigured(): bool
            {
                return false;
            }
        });

        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*']]);
        $this->assertProblem(503, 'service_unavailable');
        $this->get(self::ENDPOINTS.'/'.$id.'/secret', secret: $this->writer);
        $this->assertProblem(503, 'service_unavailable');
        $this->send('POST', self::ENDPOINTS.'/'.$id.'/secret/rotate');
        $this->assertProblem(503, 'service_unavailable');
        self::assertCount(1, $this->endpoints());
    }

    private function assertNoSecret(string $secret, string $sealed): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('whsec_', $content);
        self::assertStringNotContainsString($secret, $content);
        self::assertStringNotContainsString(json_encode($sealed, \JSON_THROW_ON_ERROR), $content);
        self::assertStringNotContainsString('"secret"', $content);
    }

    /**
     * @return list<mixed>
     */
    private function endpoints(): array
    {
        return $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM webhook_endpoint');
    }
}
