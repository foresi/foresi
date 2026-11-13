<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Delivery;

use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Webhooks\Support\DeliveryRows;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\SendWebhook;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use App\Webhooks\Infrastructure\Delivery\WebhookDeliveries;
use App\Webhooks\Infrastructure\Delivery\WebhookSweepTask;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A due delivery whose message was lost, or whose worker died holding its
 * lease, is queued again a minute after it was due.
 */
final class WebhookSweepTaskTest extends KernelTestCase
{
    public function testRedispatchesLostDueDeliveries(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $db = $em->getConnection();
        $webhooks = $container->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $webhooks);
        $webhooks->reset();

        $rows = new DeliveryRows($db);
        $tenant = TenantId::generate()->toString();
        $endpoint = Id::generate();
        $lost = $rows->insert($tenant, $endpoint, nextAttemptAt: '2026-11-13 09:58:59');
        $rows->insert($tenant, $endpoint, nextAttemptAt: '2026-11-13 09:59:30');
        $rows->insert($tenant, $endpoint, nextAttemptAt: null, kind: T::KIND_PING);
        $rows->insert($tenant, $endpoint, status: T::STATUS_FAILED, nextAttemptAt: '2026-11-13 09:00:00');

        $task = new WebhookSweepTask($container->get(WebhookDeliveries::class), $container->get(MessageBusInterface::class), new MockClock('2026-11-13 10:00:00', 'UTC'));
        $task->run();

        self::assertSame('webhook-sweep', $task->name());
        self::assertSame('5 minutes', $task->every());
        $sent = $webhooks->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame($lost, $message->deliveryId);
        self::assertSame(AttemptTrigger::Schedule, $message->trigger);
        self::assertSame($tenant, $sent[0]->last(TenantStamp::class)?->tenantId);
    }
}
