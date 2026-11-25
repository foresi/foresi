<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Mail\Application\MailMessages;
use Foresi\Mail\Domain\MailMessage;

/**
 * Plain statements, the same on SQLite and PostgreSQL. The insert does nothing
 * on a second dedup key of one address, so of two deliveries of one mail at the
 * same moment exactly one is stored, and no failed insert leaves the persistence
 * session broken.
 */
final readonly class DoctrineMailMessages implements MailMessages
{
    private const string ADD = <<<'SQL'
        INSERT INTO mail_message (id, address_id, channel, dedup_key, raw_key, raw_sha256, size, message_id_header, in_reply_to, references_header,
            from_address, subject, sent_at, received_at, verdict, verdict_basis)
        VALUES (:id, :address, :channel, :key, :raw_key, :raw_sha256, :size, :message_id, :in_reply_to, :references,
            :from_address, :subject, :sent_at, :received_at, :verdict, :basis)
        ON CONFLICT (address_id, dedup_key) DO NOTHING
        SQL;

    public function __construct(private Connection $connection, private EntityManagerInterface $em)
    {
    }

    public function has(string $addressId, string $dedupKey): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM mail_message WHERE address_id = :address AND dedup_key = :key', ['address' => $addressId, 'key' => $dedupKey]);
    }

    public function rawHashOf(string $addressId, string $dedupKey): ?string
    {
        $hash = $this->connection->fetchOne('SELECT raw_sha256 FROM mail_message WHERE address_id = :address AND dedup_key = :key', ['address' => $addressId, 'key' => $dedupKey]);

        return \is_string($hash) ? $hash : null;
    }

    public function add(MailMessage $message): bool
    {
        $references = $message->references();

        return 1 === $this->connection->executeStatement(self::ADD, [
            'id' => $message->id(),
            'address' => $message->addressId(),
            'channel' => $message->channel()->value,
            'key' => $message->dedupKey(),
            'raw_key' => $message->rawKey(),
            'raw_sha256' => $message->rawSha256(),
            'size' => $message->size(),
            'message_id' => $message->messageIdHeader(),
            'in_reply_to' => $message->inReplyTo(),
            'references' => [] === $references ? null : implode(' ', $references),
            'from_address' => $message->fromAddress(),
            'subject' => $message->subject(),
            'sent_at' => $message->sentAt(),
            'received_at' => $message->receivedAt(),
            'verdict' => $message->verdict(),
            'basis' => $message->verdictBasis(),
        ], ['size' => Types::INTEGER, 'sent_at' => Types::DATETIME_IMMUTABLE, 'received_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function countSince(string $addressId, \DateTimeImmutable $since): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM mail_message WHERE address_id = :address AND received_at >= :since',
            ['address' => $addressId, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function schedulePurge(string $id, ?\DateTimeImmutable $after): void
    {
        $this->connection->executeStatement(
            'UPDATE mail_message SET purge_after = :after WHERE id = :id',
            ['after' => $after?->setTimezone(new \DateTimeZone('UTC')), 'id' => $id],
            ['after' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function duePurge(\DateTimeImmutable $now, int $limit): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM mail_message WHERE purge_after IS NOT NULL AND purge_after <= :now ORDER BY purge_after, id LIMIT '.max(1, $limit),
            ['now' => $now->setTimezone(new \DateTimeZone('UTC'))],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return array_values(array_filter($ids, \is_string(...)));
    }

    public function delete(string $id): bool
    {
        try {
            $this->connection->executeStatement('DELETE FROM mail_message WHERE id = :id', ['id' => $id]);
        } catch (ForeignKeyConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function find(string $id): ?MailMessage
    {
        // A query, not find(): find() answers from the identity map, and the row is written by statements.
        $message = $this->em->createQuery('SELECT m, a FROM '.MailMessage::class.' m JOIN m.address a WHERE m.id = :id')
            ->setParameter('id', $id)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $message instanceof MailMessage ? $message : null;
    }
}
