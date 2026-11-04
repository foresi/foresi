<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Audit\Application\AuditActor;
use App\Audit\Application\AuditCorrelation;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Banking\Application\BankMessageParsers;
use App\Banking\Application\ClaimedMessage;
use App\Banking\Application\InboundMessages;
use App\Banking\Application\MessageRow;
use App\Banking\Application\MovementFilter;
use App\Banking\Application\ProcessingResult;
use App\Banking\Application\TrustVerdict;
use App\Banking\Domain\InboundChannel;
use App\Banking\Domain\InboundMessage;
use App\Banking\Domain\InboundStatus;
use App\Banking\Domain\MessageTrust;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;

/**
 * Plain statements in the active tenant, the same on SQLite and PostgreSQL. The
 * insert does nothing on a second dedup key of the tenant, so of two deliveries
 * of one mail at the same moment exactly one is stored, and no failed insert
 * leaves the persistence session broken. Processing writes by plain statements
 * too: the processing of a message starts the session over on the way, and the
 * row is a log no person edits. Setting a message aside and recording one anyway
 * are the changes a person makes, so each writes its own line to the audit log.
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
        SELECT m.raw_key, m.raw_sha256, m.channel, f.firm_bank_account_id
        FROM inbound_message m JOIN bank_feed f ON f.id = m.bank_feed_id AND f.tenant_id = m.tenant_id
        WHERE m.id = :id AND m.tenant_id = :tenant
        SQL;

    private const string FINISH = <<<'SQL'
        UPDATE inbound_message SET status = :status, trust = :trust, trust_basis = :basis, parser_id = :parser, parser_version = :version,
            failure = :failure, failure_detail = :detail, attempts = attempts + 1, processed_at = :at
        WHERE id = :id AND tenant_id = :tenant
        SQL;

    private const string FAILED_ATTEMPT = 'UPDATE inbound_message SET attempts = attempts + 1 WHERE id = :id AND tenant_id = :tenant';

    /** The account and firm a message came to, through its feed, all of the tenant. */
    private const string FEED = 'JOIN bank_feed f ON f.id = m.bank_feed_id AND f.tenant_id = m.tenant_id '
        .'JOIN firm_bank_account a ON a.id = f.firm_bank_account_id AND a.tenant_id = m.tenant_id '
        .'JOIN legal_entity e ON e.id = a.legal_entity_id AND e.tenant_id = m.tenant_id';

    public function __construct(
        private Connection $connection,
        private TenantContext $context,
        private EntityManagerInterface $em,
        private AuditActor $actor,
        private AuditCorrelation $correlation,
        private ClockInterface $clock,
    ) {
    }

    public function has(string $dedupKey): bool
    {
        return false !== $this->connection->fetchOne(self::HAS, ['tenant' => $this->context->current()->toString(), 'key' => $dedupKey]);
    }

    public function rawHashOf(string $dedupKey): ?string
    {
        $hash = $this->connection->fetchOne('SELECT raw_sha256 FROM inbound_message WHERE tenant_id = :tenant AND dedup_key = :key', ['tenant' => $this->context->current()->toString(), 'key' => $dedupKey]);

        return false === $hash ? null : $this->text($hash);
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

    public function lastOfFeed(string $feedId): ?InboundMessage
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM inbound_message WHERE tenant_id = :tenant AND bank_feed_id = :feed ORDER BY received_at DESC, id DESC LIMIT 1',
            ['tenant' => $this->context->current()->toString(), 'feed' => $feedId],
        );

        return false === $id ? null : $this->find($this->text($id));
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

        return new ClaimedMessage($id, $tenant, $this->text($row['firm_bank_account_id']), $this->text($row['raw_key']), $this->text($row['raw_sha256']), InboundChannel::from($this->text($row['channel'])));
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

    public function recordAccepted(string $id, InboundStatus $status): void
    {
        $row = $this->connection->fetchAssociative('SELECT status, trust_basis FROM inbound_message WHERE id = :id AND tenant_id = :tenant', ['id' => $id, 'tenant' => $this->context->current()->toString()]);
        if (false === $row) {
            throw new \LogicException('Only a stored message of the tenant is recorded anyway.');
        }
        $this->em->persist(new AuditEntry(
            InboundMessage::class,
            $id,
            AuditAction::Updated,
            // The claim marked it processing; it was rejected for its sender until now.
            ['status' => [InboundStatus::Rejected->value, $status->value], 'trust_basis' => [$this->optional($row['trust_basis']), TrustVerdict::ACCEPTED_BY_USER]],
            $this->actor->userId(),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $this->correlation->current(),
        ));
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

    public function needingAttention(\DateTimeImmutable $stuckBefore, ?string $firmId = null, ?string $accountId = null): int
    {
        [$where, $parameters] = $this->narrowed($firmId, $accountId);
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM inbound_message m '.self::FEED.' WHERE '.$where.' AND (m.status IN (:waiting) OR (m.status = :received AND m.received_at < :before))',
            $parameters + ['waiting' => [InboundStatus::Failed->value, InboundStatus::Rejected->value], 'received' => InboundStatus::Received->value, 'before' => $stuckBefore],
            ['waiting' => ArrayParameterType::STRING, 'before' => Types::DATETIME_IMMUTABLE],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function count(MovementFilter $filter): int
    {
        [$where, $parameters] = $this->narrowed($filter->firmId, $filter->accountId);
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM inbound_message m '.self::FEED.' WHERE '.$where, $parameters);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function row(string $id, \DateTimeImmutable $stuckBefore): ?MessageRow
    {
        return $this->listed('m.tenant_id = :tenant AND m.id = :id', ['tenant' => $this->context->current()->toString(), 'id' => $id], $stuckBefore, 0, 1)[0] ?? null;
    }

    public function rows(MovementFilter $filter, \DateTimeImmutable $stuckBefore, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->narrowed($filter->firmId, $filter->accountId);

        return $this->listed($where, $parameters, $stuckBefore, $offset, $limit);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<MessageRow>
     */
    private function listed(string $where, array $parameters, \DateTimeImmutable $stuckBefore, int $offset, int $limit): array
    {
        $sql = 'SELECT m.id, m.received_at, m.from_address, m.subject, m.status, m.failure, m.failure_detail, m.trust, m.trust_basis, m.parser_id, m.parser_version, m.attempts, '
            .'a.label AS account_label, a.iban AS account_iban, e.name AS firm_name, e.address_country_code AS firm_country FROM inbound_message m '.self::FEED.' WHERE '.$where
            .' ORDER BY m.received_at DESC, m.id DESC LIMIT '.max(1, $limit).' OFFSET '.max(0, $offset);
        $type = Type::getType(Types::DATETIME_IMMUTABLE);
        $platform = $this->connection->getDatabasePlatform();

        return array_map(function (array $row) use ($type, $platform, $stuckBefore): MessageRow {
            $receivedAt = $type->convertToPHPValue($row['received_at'], $platform);
            \assert($receivedAt instanceof \DateTimeImmutable);
            $status = InboundStatus::from($this->text($row['status']));
            $parserId = $this->optional($row['parser_id']);

            return new MessageRow(
                $this->text($row['id']),
                $receivedAt,
                $this->optional($row['from_address']),
                $this->text($row['subject']),
                $status,
                $this->optional($row['failure']),
                $this->optional($row['failure_detail']),
                null === $row['trust'] ? null : MessageTrust::from($this->text($row['trust'])),
                $this->optional($row['trust_basis']),
                // The signature of the parser set is no parser's name.
                null !== $parserId && str_starts_with($parserId, BankMessageParsers::SIGNATURE_PREFIX) ? null : $parserId,
                is_numeric($row['parser_version']) ? (int) $row['parser_version'] : null,
                is_numeric($row['attempts']) ? (int) $row['attempts'] : 0,
                $this->text($row['firm_name']),
                $this->text($row['firm_country']),
                $this->text($row['account_label']),
                $this->text($row['account_iban']),
                InboundStatus::Received === $status && $receivedAt < $stuckBefore,
            );
        }, $this->connection->fetchAllAssociative($sql, $parameters));
    }

    public function dismiss(string $id, array $from, ?\DateTimeImmutable $receivedBefore): ?InboundStatus
    {
        $tenant = $this->context->current()->toString();
        $status = $this->connection->fetchOne('SELECT status FROM inbound_message WHERE id = :id AND tenant_id = :tenant', ['id' => $id, 'tenant' => $tenant]);
        $was = \is_string($status) ? InboundStatus::tryFrom($status) : null;
        if (!$was instanceof InboundStatus || !\in_array($was, $from, true)) {
            return null;
        }
        $sql = 'UPDATE inbound_message SET status = :dismissed WHERE id = :id AND tenant_id = :tenant AND status = :was';
        $parameters = ['dismissed' => InboundStatus::Dismissed->value, 'id' => $id, 'tenant' => $tenant, 'was' => $was->value];
        $types = [];
        if (InboundStatus::Received === $was) {
            if (!$receivedBefore instanceof \DateTimeImmutable) {
                return null;
            }
            // Still in the queue: the worker may take it any moment, so it is left to it.
            $sql .= ' AND received_at < :before';
            $parameters['before'] = $receivedBefore;
            $types['before'] = Types::DATETIME_IMMUTABLE;
        }
        if (1 !== $this->connection->executeStatement($sql, $parameters, $types)) {
            return null;
        }
        $this->em->persist(new AuditEntry(
            InboundMessage::class,
            $id,
            AuditAction::Updated,
            ['status' => [$was->value, InboundStatus::Dismissed->value]],
            $this->actor->userId(),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $this->correlation->current(),
        ));

        return $was;
    }

    /**
     * The messages of the tenant, of one firm or account when named.
     *
     * @return array{string, array<string, string>}
     */
    private function narrowed(?string $firmId, ?string $accountId): array
    {
        $where = 'm.tenant_id = :tenant';
        $parameters = ['tenant' => $this->context->current()->toString()];
        if (null !== $firmId) {
            $where .= ' AND a.legal_entity_id = :firm';
            $parameters['firm'] = $firmId;
        }
        if (null !== $accountId) {
            $where .= ' AND a.id = :account';
            $parameters['account'] = $accountId;
        }

        return [$where, $parameters];
    }

    private function optional(mixed $value): ?string
    {
        return null === $value ? null : $this->text($value);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string: '.get_debug_type($value));
    }
}
