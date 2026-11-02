<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\BankMessageParsers;
use App\Banking\Application\ClaimedMessage;
use App\Banking\Application\InboundMessages;
use App\Banking\Application\ProcessingResult;
use App\Banking\Domain\InboundMessage;
use App\Banking\Domain\InboundStatus;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Plain statements in the active tenant, the same on SQLite and PostgreSQL. The
 * insert does nothing on a second dedup key of the tenant, so of two deliveries
 * of one mail at the same moment exactly one is stored, and no failed insert
 * leaves the persistence session broken. Processing writes by plain statements
 * too: the processing of a message starts the session over on the way, and the
 * row is a log no person edits.
 */
final readonly class DoctrineInboundMessages implements InboundMessages
{
    private const string HAS = 'SELECT 1 FROM inbound_message WHERE tenant_id = :tenant AND dedup_key = :key';

    private const string ADD = <<<'SQL'
        INSERT INTO inbound_message (id, tenant_id, bank_feed_id, channel, dedup_key, raw_key, raw_sha256, size, from_address, subject, received_at, status, attempts)
        VALUES (:id, :tenant, :feed, :channel, :key, :raw_key, :raw_sha256, :size, :from_address, :subject, :received_at, :status, 0)
        ON CONFLICT (tenant_id, dedup_key) DO NOTHING
        SQL;

    private const string CLAIMED = <<<'SQL'
        SELECT m.raw_key, m.raw_sha256, f.firm_bank_account_id
        FROM inbound_message m JOIN bank_feed f ON f.id = m.bank_feed_id AND f.tenant_id = m.tenant_id
        WHERE m.id = :id AND m.tenant_id = :tenant
        SQL;

    private const string FINISH = <<<'SQL'
        UPDATE inbound_message SET status = :status, trust = :trust, trust_basis = :basis, parser_id = :parser, parser_version = :version,
            failure = :failure, failure_detail = :detail, attempts = attempts + 1, processed_at = :at
        WHERE id = :id AND tenant_id = :tenant
        SQL;

    private const string FAILED_ATTEMPT = 'UPDATE inbound_message SET attempts = attempts + 1 WHERE id = :id AND tenant_id = :tenant';

    public function __construct(private Connection $connection, private TenantContext $context, private EntityManagerInterface $em)
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

    public function find(string $id): ?InboundMessage
    {
        // A query, not find(): find() answers from the identity map without the tenant filter, and the row is written by statements.
        $message = $this->em->createQuery('SELECT m FROM '.InboundMessage::class.' m WHERE m.id = :id')
            ->setParameter('id', $id)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $message instanceof InboundMessage ? $message : null;
    }

    public function claim(string $id, array $from, ?string $failure = null): ?ClaimedMessage
    {
        $tenant = $this->context->current()->toString();
        $sql = 'UPDATE inbound_message SET status = :processing WHERE id = :id AND tenant_id = :tenant AND status IN (:from)';
        $parameters = ['processing' => InboundStatus::Processing->value, 'id' => $id, 'tenant' => $tenant, 'from' => array_map(static fn (InboundStatus $status): string => $status->value, $from)];
        if (null !== $failure) {
            $sql .= ' AND failure = :failure';
            $parameters['failure'] = $failure;
        }
        if (1 !== $this->connection->executeStatement($sql, $parameters, ['from' => ArrayParameterType::STRING])) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::CLAIMED, ['id' => $id, 'tenant' => $tenant]);
        if (false === $row) {
            throw new \LogicException('A claimed message has no feed of its tenant.');
        }

        return new ClaimedMessage($id, $tenant, $this->text($row['firm_bank_account_id']), $this->text($row['raw_key']), $this->text($row['raw_sha256']));
    }

    public function finish(string $id, ProcessingResult $result, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(self::FINISH, [
            'status' => $result->status->value,
            'trust' => $result->verdict?->trust->value,
            'basis' => $result->verdict?->basis,
            'parser' => $result->parserId,
            'version' => $result->parserVersion,
            'failure' => $result->failure,
            'detail' => $result->detail,
            'at' => $at,
            'id' => $id,
            'tenant' => $this->context->current()->toString(),
        ], ['version' => null === $result->parserVersion ? ParameterType::NULL : ParameterType::INTEGER, 'at' => Types::DATETIME_IMMUTABLE]);
    }

    public function countFailedAttempt(string $id): void
    {
        $this->connection->executeStatement(self::FAILED_ATTEMPT, ['id' => $id, 'tenant' => $this->context->current()->toString()]);
    }

    public function due(array $parserVersions, string $signature, int $limit): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('inbound_message')
            ->where('tenant_id = :tenant')
            ->andWhere('status IN (:statuses)')
            ->orderBy('received_at')
            ->addOrderBy('id')
            ->setMaxResults(max(1, $limit))
            ->setParameter('tenant', $this->context->current()->toString())
            ->setParameter('statuses', [InboundStatus::Failed->value, InboundStatus::NotBankMail->value], ArrayParameterType::STRING)
            ->setParameter('prefix', BankMessageParsers::SIGNATURE_PREFIX.'%')
            ->setParameter('signature', $signature);
        $stale = ['(parser_id LIKE :prefix AND parser_id <> :signature)'];
        $index = 0;
        foreach ($parserVersions as $parserId => $version) {
            $stale[] = \sprintf('(parser_id = :parser%1$d AND parser_version < :version%1$d)', $index);
            $query->setParameter('parser'.$index, $parserId)->setParameter('version'.$index, $version, ParameterType::INTEGER);
            ++$index;
        }
        $query->andWhere('('.implode(' OR ', $stale).')');

        return array_map($this->text(...), $query->executeQuery()->fetchFirstColumn());
    }

    public function needingAttention(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM inbound_message WHERE tenant_id = :tenant AND status IN (:statuses)',
            ['tenant' => $this->context->current()->toString(), 'statuses' => [InboundStatus::Failed->value, InboundStatus::Rejected->value]],
            ['statuses' => ArrayParameterType::STRING],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string: '.get_debug_type($value));
    }
}
