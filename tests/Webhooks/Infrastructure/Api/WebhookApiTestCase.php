<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Api;

use App\Identity\Domain\ApiScope;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tests\Shared\Infrastructure\Api\ApiTestCase;
use App\Tests\Webhooks\Support\DeliveryRows;
use App\Webhooks\Domain\DisabledReason;
use App\Webhooks\Domain\EndpointUrl;
use App\Webhooks\Domain\EventTypes;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Crypto\SodiumWebhookSecrets;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A member of the organisation with a token that reads and writes, the webhooks
 * queue emptied, and helpers that send JSON bodies as a client would.
 */
abstract class WebhookApiTestCase extends ApiTestCase
{
    protected const string ENDPOINTS = '/api/v1/webhook-endpoints';

    protected string $writer;
    protected DeliveryRows $rows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::Read, ApiScope::Write]);
        $this->rows = new DeliveryRows($this->em()->getConnection());
        $this->webhooks()->reset();
    }

    /**
     * @param array<mixed>|null $body
     */
    protected function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * Registers an endpoint over the API and answers its id.
     *
     * @param list<string> $eventTypes
     */
    protected function created(array $eventTypes = ['invoicing.invoice.paid'], string $url = 'https://hooks.example.com/foresi'): string
    {
        $this->send('POST', self::ENDPOINTS, ['url' => $url, 'event_types' => $eventTypes]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        $this->webhooks()->reset();

        return $id;
    }

    /**
     * An endpoint stored straight in an organisation, owned by the user, with
     * no ping. Its secret is sealed with a key of its own, so storing it starts
     * none of the services a test may still replace.
     *
     * @param list<string> $eventTypes
     */
    protected function stored(TenantId $tenant, array $eventTypes = ['invoicing.*'], bool $active = true): string
    {
        return $this->inTenant($tenant, function () use ($eventTypes, $active): string {
            $secrets = new SodiumWebhookSecrets('stored:'.base64_encode(str_repeat("\x07", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
            $now = new \DateTimeImmutable('2026-11-13 10:00:00');
            $endpoint = WebhookEndpoint::create(Id::generate(), new EndpointUrl('https://hooks.example.com/stored'), null, EventTypes::of($eventTypes), 1, $secrets->seal(WebhookSecret::generate()), $this->user->id(), $now);
            if (!$active) {
                $endpoint->disable(DisabledReason::Manual, $now);
            }
            $this->em()->persist($endpoint);

            return $endpoint->id();
        });
    }

    protected function endpoint(string $id): ?WebhookEndpoint
    {
        $this->em()->clear();

        return $this->inTenant($this->tenant, fn (): ?WebhookEndpoint => $this->em()->find(WebhookEndpoint::class, $id));
    }

    protected function webhooks(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @return list<Envelope>
     */
    protected function queued(): array
    {
        return array_values([...$this->webhooks()->getSent()]);
    }
}
