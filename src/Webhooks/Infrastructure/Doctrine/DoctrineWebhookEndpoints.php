<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Webhooks\Application\WebhookEndpoints;
use Foresi\Webhooks\Domain\WebhookEndpoint;
use Foresi\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;

/**
 * The endpoints through the entity manager. The delivery tables hold no
 * entity, so removing an endpoint deletes its rows there with plain statements
 * in the same transaction.
 */
final readonly class DoctrineWebhookEndpoints implements WebhookEndpoints
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?WebhookEndpoint
    {
        return $this->em->find(WebhookEndpoint::class, $id);
    }

    public function add(WebhookEndpoint $endpoint): void
    {
        $this->em->persist($endpoint);
    }

    public function remove(WebhookEndpoint $endpoint): void
    {
        $db = $this->em->getConnection();
        $deliveries = $db->createQueryBuilder()
            ->select(T::ID)
            ->from(T::DELIVERY)
            ->where(T::ENDPOINT_ID.' = :endpoint');
        $db->createQueryBuilder()
            ->delete(T::ATTEMPT)
            ->where(T::DELIVERY_ID.' IN ('.$deliveries->getSQL().')')
            ->setParameter('endpoint', $endpoint->id())
            ->executeStatement();
        $db->createQueryBuilder()
            ->delete(T::DELIVERY)
            ->where(T::ENDPOINT_ID.' = :endpoint')
            ->setParameter('endpoint', $endpoint->id())
            ->executeStatement();
        $this->em->remove($endpoint);
    }

    public function before(?string $beforeId, int $limit): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('e')
            ->from(WebhookEndpoint::class, 'e')
            ->orderBy('e.id', 'DESC')
            ->setMaxResults($limit);
        if (null !== $beforeId) {
            $query->where('e.id < :before')->setParameter('before', $beforeId);
        }

        /* @var list<WebhookEndpoint> */
        return $query->getQuery()->getResult();
    }
}
