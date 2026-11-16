<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure;

use App\Audit\Domain\AuditEntry;
use App\Identity\Domain\Role;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Webhooks\Domain\EndpointUrl;
use App\Webhooks\Domain\EventTypes;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Crypto\SodiumWebhookSecrets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An endpoint in the database, and what the audit log keeps of it: never a
 * secret, neither sealed nor in the clear.
 */
final class WebhookEndpointPersistenceTest extends KernelTestCase
{
    public function testTheSecretsNeverReachTheAuditLog(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $context = self::getContainer()->get(TenantContext::class);
        $fixtures = new IdentityFixtures($em, $context);
        $fixtures->rebuildSchema();
        $user = $fixtures->user();
        $tenant = $fixtures->tenant('Tenant A');
        $fixtures->join($user, $tenant, Role::Owner);
        $secrets = new SodiumWebhookSecrets('k1:'.base64_encode(str_repeat("\x07", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $first = WebhookSecret::generate();
        $second = WebhookSecret::generate();
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');

        $context->switchTo($tenant);
        $endpoint = WebhookEndpoint::create(Id::generate(), new EndpointUrl('https://hooks.example.com/x'), null, EventTypes::of(['invoicing.*']), 1, $secrets->seal($first), $user->id(), $now);
        $em->persist($endpoint);
        $em->flush();
        $endpoint->rotate($secrets->seal($second), $now->modify('+24 hours'), $now->modify('+1 minute'));
        $em->flush();
        $em->clear();

        $stored = $em->find(WebhookEndpoint::class, $endpoint->id());
        self::assertInstanceOf(WebhookEndpoint::class, $stored);
        self::assertSame([$second->key(), $first->key()], array_map(static fn (string $sealed): string => $secrets->open($sealed)->key(), $stored->signingSecrets($now)));
        self::assertSame(['invoicing.*'], $stored->eventTypes()->patterns());

        /** @var list<AuditEntry> $entries */
        $entries = $em->createQuery('SELECT e FROM '.AuditEntry::class.' e WHERE e.entityClass = :class ORDER BY e.occurredAt, e.id')
            ->setParameter('class', WebhookEndpoint::class)
            ->getResult();
        self::assertCount(2, $entries);
        $logged = (string) json_encode(array_map(static fn (AuditEntry $entry): array => $entry->changes(), $entries));
        self::assertStringNotContainsString('whsec_', $logged);
        self::assertStringNotContainsString('k1:', $logged);
        foreach ($entries as $entry) {
            self::assertArrayNotHasKey('secret', $entry->changes());
            self::assertArrayNotHasKey('previousSecret', $entry->changes());
        }
        self::assertArrayHasKey('previousExpiresAt', $entries[1]->changes());
    }
}
