<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Mail\Application\AddressAlreadyActive;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Domain\InboundAddress;

/**
 * Through the ORM, except touch(), which is plain SQL by the address's id. The
 * token hash is the only key mail is routed by.
 */
final readonly class DoctrineInboundAddresses implements InboundAddressStore
{
    private const string TOUCH = 'UPDATE mail_inbound_address SET last_received_at = :at WHERE id = :id AND (last_received_at IS NULL OR last_received_at < :at)';

    /** The count after the update says whether it was the first of the day: the else branch sets 1, the other one adds to a count of 1 or more. */
    private const string DROP = <<<'SQL'
        UPDATE mail_inbound_address
        SET dropped_on_limit = CASE WHEN limit_reached_on = :day THEN dropped_on_limit + 1 ELSE 1 END, limit_reached_on = :day
        WHERE id = :id
        RETURNING dropped_on_limit
        SQL;

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

    public function ownedBy(string $ownerUserId, ?string $purpose = null): array
    {
        $query = $this->em->createQuery('SELECT a FROM '.InboundAddress::class.' a WHERE a.ownerUserId = :owner'.(null === $purpose ? '' : ' AND a.purpose = :purpose').' ORDER BY a.createdAt ASC, a.id ASC')
            ->setParameter('owner', $ownerUserId);
        if (null !== $purpose) {
            $query->setParameter('purpose', $purpose);
        }

        /** @var list<InboundAddress> $addresses */
        $addresses = $query->getResult();

        return $addresses;
    }

    public function activeOf(string $ownerUserId, string $purpose): ?InboundAddress
    {
        $address = $this->em->createQuery('SELECT a FROM '.InboundAddress::class.' a WHERE a.ownerUserId = :owner AND a.purpose = :purpose AND a.revokedAt IS NULL')
            ->setParameter('owner', $ownerUserId)
            ->setParameter('purpose', $purpose)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $address instanceof InboundAddress ? $address : null;
    }

    public function add(InboundAddress $address): void
    {
        $this->em->persist($address);
    }

    public function flush(): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $violation) {
            // PostgreSQL names the index; SQLite names the columns. Any other unique key is not this case.
            $message = $violation->getMessage();
            if (!str_contains($message, 'uniq_mail_inbound_address_owner_purpose') && (!str_contains($message, 'owner_user_id') || !str_contains($message, 'purpose'))) {
                throw $violation;
            }

            throw new AddressAlreadyActive('The person has an active address for this purpose.', $violation->getCode(), $violation);
        }
    }

    public function touch(string $id, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(self::TOUCH, ['at' => $at, 'id' => $id], ['at' => Types::DATETIME_IMMUTABLE]);
    }

    public function recordDrop(string $id, \DateTimeImmutable $day): int
    {
        $dropped = $this->connection->fetchOne(self::DROP, ['day' => $day, 'id' => $id], ['day' => Types::DATE_IMMUTABLE]);

        return is_numeric($dropped) ? (int) $dropped : 0;
    }
}
