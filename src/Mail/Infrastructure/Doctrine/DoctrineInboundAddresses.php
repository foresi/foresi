<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Domain\InboundAddress;

/**
 * Through the ORM, except touch(), which is plain SQL by the address's id. The
 * token hash is the only key mail is routed by.
 */
final readonly class DoctrineInboundAddresses implements InboundAddressStore
{
    private const string TOUCH = 'UPDATE mail_inbound_address SET last_received_at = :at WHERE id = :id AND (last_received_at IS NULL OR last_received_at < :at)';

    public function __construct(private EntityManagerInterface $em, private Connection $connection)
    {
    }

    public function routable(string $tokenHash): ?InboundAddress
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $tokenHash)) {
            return null;
        }
        $address = $this->em->createQuery('SELECT a FROM '.InboundAddress::class.' a WHERE a.tokenHash = :hash AND a.revokedAt IS NULL')
            ->setParameter('hash', $tokenHash)
            ->getOneOrNullResult();

        return $address instanceof InboundAddress ? $address : null;
    }

    public function get(string $id): ?InboundAddress
    {
        return $this->em->find(InboundAddress::class, $id);
    }

    public function add(InboundAddress $address): void
    {
        $this->em->persist($address);
    }

    public function touch(string $id, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(self::TOUCH, ['at' => $at, 'id' => $id], ['at' => Types::DATETIME_IMMUTABLE]);
    }
}
