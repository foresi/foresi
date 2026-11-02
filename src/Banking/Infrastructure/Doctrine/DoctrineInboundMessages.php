<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\InboundMessages;
use App\Banking\Domain\InboundMessage;
use App\Banking\Domain\InboundStatus;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Plain statements in the active tenant, the same on SQLite and PostgreSQL. The
 * insert does nothing on a second dedup key of the tenant, so of two deliveries
 * of one mail at the same moment exactly one is stored, and no failed insert
 * leaves the persistence session broken.
 */
final readonly class DoctrineInboundMessages implements InboundMessages
{
    private const string HAS = 'SELECT 1 FROM inbound_message WHERE tenant_id = :tenant AND dedup_key = :key';

    private const string ADD = <<<'SQL'
        INSERT INTO inbound_message (id, tenant_id, bank_feed_id, channel, dedup_key, raw_key, raw_sha256, size, from_address, subject, received_at, status, attempts)
        VALUES (:id, :tenant, :feed, :channel, :key, :raw_key, :raw_sha256, :size, :from_address, :subject, :received_at, :status, 0)
        ON CONFLICT (tenant_id, dedup_key) DO NOTHING
        SQL;

    public function __construct(private Connection $connection, private TenantContext $context)
    {
    }

    public function has(string $dedupKey): bool
    {
        return false !== $this->connection->fetchOne(self::HAS, ['tenant' => $this->context->current()->toString(), 'key' => $dedupKey]);
    }

    public function add(InboundMessage $message): bool
    {
        return 1 === $this->connection->executeStatement(self::ADD, [
            'id' => $message->id(),
            'tenant' => $this->context->current()->toString(),
            'feed' => $message->feedId(),
            'channel' => $message->channel()->value,
            'key' => $message->dedupKey(),
            'raw_key' => $message->rawKey(),
            'raw_sha256' => $message->rawSha256(),
            'size' => $message->size(),
            'from_address' => $message->fromAddress(),
            'subject' => $message->subject(),
            'received_at' => $message->receivedAt(),
            'status' => InboundStatus::Received->value,
        ], ['size' => Types::INTEGER, 'received_at' => Types::DATETIME_IMMUTABLE]);
    }
}
