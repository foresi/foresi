<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Webhooks\Infrastructure\Delivery;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Webhooks\Support\DeliveryRows;
use Foresi\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use Foresi\Webhooks\Infrastructure\Delivery\PruneWebhookDeliveriesTask;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Deliveries and their attempts are kept thirty days, like the event log. One
 * still waiting for an attempt is never deleted.
 */
final class PruneWebhookDeliveriesTaskTest extends KernelTestCase
{
    public function testKeepsThirtyDays(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $db = $em->getConnection();

        $rows = new DeliveryRows($db);
        $endpoint = Id::generate();
        $oldSucceeded = $rows->insert($endpoint, status: T::STATUS_SUCCEEDED, nextAttemptAt: null, createdAt: '2026-10-12 09:59:59');
        $oldFailed = $rows->insert($endpoint, status: T::STATUS_FAILED, nextAttemptAt: null, createdAt: '2026-09-01 10:00:00');
        $oldPending = $rows->insert($endpoint, nextAttemptAt: '2026-11-11 12:00:00', createdAt: '2026-09-01 10:00:00');
        $recent = $rows->insert($endpoint, status: T::STATUS_FAILED, nextAttemptAt: null, createdAt: '2026-10-12 10:00:01');
        foreach ([$oldSucceeded, $oldFailed, $oldPending, $recent] as $delivery) {
            $rows->attempt($delivery, '2026-10-12 09:00:00');
        }

        $task = new PruneWebhookDeliveriesTask($db, new MockClock('2026-11-11 10:00:00', 'UTC'));
        $task->run();

        self::assertSame('webhook-delivery-prune', $task->name());
        self::assertSame('1 day', $task->every());
        $left = $db->fetchFirstColumn('SELECT id FROM webhook_delivery ORDER BY created_at');
        self::assertEqualsCanonicalizing([$oldPending, $recent], $left);
        $attempts = $db->fetchFirstColumn('SELECT delivery_id FROM webhook_delivery_attempt');
        self::assertEqualsCanonicalizing([$oldPending, $recent], $attempts);
    }
}
