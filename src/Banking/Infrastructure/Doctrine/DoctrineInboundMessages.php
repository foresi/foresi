<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Audit\Application\AuditActor;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Banking\Application\BankMessageParsers;
use Foresi\Banking\Application\ClaimedMessage;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\MessageRow;
use Foresi\Banking\Application\MovementFilter;
use Foresi\Banking\Application\ProcessingResult;
use Foresi\Banking\Application\TrustVerdict;
use Foresi\Banking\Domain\InboundChannel;
use Foresi\Banking\Domain\InboundMessage;
use Foresi\Banking\Domain\InboundStatus;
use Foresi\Banking\Domain\MessageTrust;
use Foresi\Shared\Application\View\ViewFilter;
use Psr\Clock\ClockInterface;

/**
 * Plain statements, the same on SQLite and PostgreSQL. The insert does
 * nothing on a second dedup key, so of two deliveries
 * of one mail at the same moment exactly one is stored, and no failed insert
 * leaves the persistence session broken. Processing writes by plain statements
 * too: the processing of a message starts the session over on the way, and the
 * row is a log no person edits. Setting a message aside and recording one anyway
 * are the changes a person makes, so each writes its own line to the audit log.
 */
final readonly class DoctrineInboundMessages implements InboundMessages
{
    private const string HAS = 'SELECT 1 FROM inbound_message WHERE dedup_key = :key';

    private const string ADD = <<<'SQL'
        INSERT INTO inbound_message (id, bank_feed_id, channel, dedup_key, raw_key, raw_sha256, size, from_address, subject, received_at, status, attempts)
        VALUES (:id, :feed, :channel, :key, :raw_key, :raw_sha256, :size, :from_address, :subject, :received_at, :status, 0)
        ON CONFLICT (dedup_key) DO NOTHING
        SQL;

    private const string CLAIMED = <<<'SQL'
        SELECT m.raw_key, m.raw_sha256, m.channel, f.firm_bank_account_id
        FROM inbound_message m JOIN bank_feed f ON f.id = m.bank_feed_id
        WHERE m.id = :id
        SQL;

    private const string FINISH = <<<'SQL'
        UPDATE inbound_message SET status = :status, trust = :trust, trust_basis = :basis, parser_id = :parser, parser_version = :version,
            failure = :failure, failure_detail = :detail, attempts = attempts + 1, processed_at = :at
        WHERE id = :id
        SQL;

    private const string FAILED_ATTEMPT = 'UPDATE inbound_message SET attempts = attempts + 1 WHERE id = :id';

    /** The account and firm a message came to, through its feed. */
    private const string FEED = 'JOIN bank_feed f ON f.id = m.bank_feed_id '
        .'JOIN firm_bank_account a ON a.id = f.firm_bank_account_id '
        .'JOIN legal_entity e ON e.id = a.legal_entity_id';

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private AuditActor $actor,
        private AuditCorrelation $correlation,
        private ClockInterface $clock,
        private MovementConditions $conditions,
    ) {
    }

    public function has(string $dedupKey): bool
    {
        return false !== $this->connection->fetchOne(self::HAS, ['key' => $dedupKey]);
    }

    public function rawHashOf(string $dedupKey): ?string
    {
        $hash = $this->connection->fetchOne('SELECT raw_sha256 FROM inbound_message WHERE dedup_key = :key', ['key' => $dedupKey]);

        return false === $hash ? null : $this->text($hash);
    }

    public function add(InboundMessage $message): bool
    {
        return 1 === $this->connection->executeStatement(self::ADD, [
            'id' => $message->id(),
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
        // A query, not find(): find() answers from the identity map, and the row is written by statements.
        $message = $this->em->createQuery('SELECT m FROM '.InboundMessage::class.' m WHERE m.id = :id')
            ->setParameter('id', $id)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $message instanceof InboundMessage ? $message : null;
    }

    public function lastOfFeed(string $feedId): ?InboundMessage
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM inbound_message WHERE bank_feed_id = :feed ORDER BY received_at DESC, id DESC LIMIT 1',
            ['feed' => $feedId],
        );

        return false === $id ? null : $this->find($this->text($id));
    }

    public function claim(string $id, array $from, ?string $failure = null): ?ClaimedMessage
    {
        $sql = 'UPDATE inbound_message SET status = :processing WHERE id = :id AND status IN (:from)';
        $parameters = ['processing' => InboundStatus::Processing->value, 'id' => $id, 'from' => array_map(static fn (InboundStatus $status): string => $status->value, $from)];
        if (null !== $failure) {
            $sql .= ' AND failure = :failure';
            $parameters['failure'] = $failure;
        }
        if (1 !== $this->connection->executeStatement($sql, $parameters, ['from' => ArrayParameterType::STRING])) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::CLAIMED, ['id' => $id]);
        if (false === $row) {
            throw new \LogicException('A claimed message has no feed.');
        }

        return new ClaimedMessage($id, $this->text($row['firm_bank_account_id']), $this->text($row['raw_key']), $this->text($row['raw_sha256']), InboundChannel::from($this->text($row['channel'])));
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
        ], ['version' => null === $result->parserVersion ? ParameterType::NULL : ParameterType::INTEGER, 'at' => Types::DATETIME_IMMUTABLE]);
    }

    public function recordAccepted(string $id, InboundStatus $status): void
    {
        $row = $this->connection->fetchAssociative('SELECT status, trust_basis FROM inbound_message WHERE id = :id', ['id' => $id]);
        if (false === $row) {
            throw new \LogicException('Only a stored message is recorded anyway.');
        }
        $this->em->persist(new AuditEntry(
            InboundMessage::class,
            $id,
            AuditAction::Updated,
            // The claim marked it processing; it was rejected for its sender until now.
            ['status' => [InboundStatus::Rejected->value, $status->value], 'trust_basis' => [$this->optional($row['trust_basis']), TrustVerdict::ACCEPTED_BY_USER]],
            $this->actor->userId(),
            $this->actor->kind(),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $this->correlation->current(),
        ));
    }

    public function countFailedAttempt(string $id): void
    {
        $this->connection->executeStatement(self::FAILED_ATTEMPT, ['id' => $id]);
    }

    public function due(array $parserVersions, string $signature, int $limit): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('inbound_message')
            ->where('status IN (:statuses)')
            ->orderBy('received_at')
            ->addOrderBy('id')
            ->setMaxResults(max(1, $limit))
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

    public function needingAttention(\DateTimeImmutable $stuckBefore, ?ViewFilter $view = null): int
    {
        [$where, $parameters, $types] = $this->conditions->mail($view ?? new ViewFilter(), 'v');
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM inbound_message m '.self::FEED.' WHERE '.$where.' AND (m.status IN (:waiting) OR (m.status = :received AND m.received_at < :before))',
            $parameters + ['waiting' => [InboundStatus::Failed->value, InboundStatus::Rejected->value], 'received' => InboundStatus::Received->value, 'before' => $stuckBefore],
            $types + ['waiting' => ArrayParameterType::STRING, 'before' => Types::DATETIME_IMMUTABLE],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function count(MovementFilter $filter): int
    {
        [$where, $parameters, $types] = $this->conditions->mail($filter->view, 'v');
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM inbound_message m '.self::FEED.' WHERE '.$where, $parameters, $types);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function row(string $id, \DateTimeImmutable $stuckBefore): ?MessageRow
    {
        return $this->listed('m.id = :id', ['id' => $id], $stuckBefore, 0, 1)[0] ?? null;
    }

    public function rows(MovementFilter $filter, \DateTimeImmutable $stuckBefore, int $offset, int $limit): array
    {
        [$where, $parameters, $types] = $this->conditions->mail($filter->view, 'v');

        return $this->listed($where, $parameters, $stuckBefore, $offset, $limit, $types);
    }

    /**
     * @param array<string, mixed>              $parameters
     * @param array<string, ArrayParameterType> $types
     *
     * @return list<MessageRow>
     */
    private function listed(string $where, array $parameters, \DateTimeImmutable $stuckBefore, int $offset, int $limit, array $types = []): array
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
        }, $this->connection->fetchAllAssociative($sql, $parameters, $types));
    }

    public function dismiss(string $id, array $from, ?\DateTimeImmutable $receivedBefore): ?InboundStatus
    {
        $status = $this->connection->fetchOne('SELECT status FROM inbound_message WHERE id = :id', ['id' => $id]);
        $was = \is_string($status) ? InboundStatus::tryFrom($status) : null;
        if (!$was instanceof InboundStatus || !\in_array($was, $from, true)) {
            return null;
        }
        $sql = 'UPDATE inbound_message SET status = :dismissed WHERE id = :id AND status = :was';
        $parameters = ['dismissed' => InboundStatus::Dismissed->value, 'id' => $id, 'was' => $was->value];
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
            $this->actor->kind(),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $this->correlation->current(),
        ));

        return $was;
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
