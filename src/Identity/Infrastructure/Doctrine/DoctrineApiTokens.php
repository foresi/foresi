<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\ApiTokenRecord;
use App\Identity\Application\ApiTokens;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Doctrine\TenantFilterBypass;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The reads across tenants run with the tenant filter suspended and return
 * scalars only; the records are built once the filter is back.
 */
final readonly class DoctrineApiTokens implements ApiTokens
{
    private const string RECORD = 'SELECT t.id AS id, t.tenantId AS tenantId, t.userId AS userId, t.name AS name, t.scopes AS scopes, t.lastFour AS lastFour, t.createdAt AS createdAt, t.expiresAt AS expiresAt, t.lastUsedAt AS lastUsedAt, t.revokedAt AS revokedAt FROM '.ApiToken::class.' t';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantFilterBypass $bypass,
    ) {
    }

    public function add(ApiToken $token): void
    {
        $this->entityManager->persist($token);
    }

    public function find(string $id): ?ApiToken
    {
        return $this->entityManager->getRepository(ApiToken::class)->findOneBy(['id' => $id]);
    }

    public function ofUser(string $userId): array
    {
        return $this->entityManager->getRepository(ApiToken::class)->findBy(['userId' => $userId], ['id' => 'DESC']);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(ApiToken::class)->findBy([], ['id' => 'DESC']);
    }

    /**
     * A request names its token before any organisation is active.
     */
    public function byHash(string $tokenHash): ?ApiTokenRecord
    {
        $rows = $this->rows(' WHERE t.tokenHash = :hash', ['hash' => [$tokenHash, null]]);

        return [] === $rows ? null : $this->record($rows[0]);
    }

    public function records(?TenantId $tenant): array
    {
        $rows = $tenant instanceof TenantId
            ? $this->rows(' WHERE t.tenantId = :tenant ORDER BY t.id DESC', ['tenant' => [$tenant, 'tenant_id']])
            : $this->rows(' ORDER BY t.id DESC', []);

        return array_map($this->record(...), $rows);
    }

    public function tenantOf(string $id): ?TenantId
    {
        $rows = $this->rows(' WHERE t.id = :id', ['id' => [$id, null]]);

        return [] === $rows ? null : TenantId::fromString($rows[0]['tenantId']);
    }

    /**
     * An update query, not a change of the entity: it runs before any
     * organisation is active and writes no audit entry.
     */
    public function touch(string $id, \DateTimeImmutable $now, \DateTimeImmutable $usedAfter): void
    {
        $this->bypass->run(fn (): int => $this->entityManager
            ->createQuery('UPDATE '.ApiToken::class.' t SET t.lastUsedAt = :now WHERE t.id = :id AND (t.lastUsedAt IS NULL OR t.lastUsedAt <= :after)')
            ->setParameter('now', $now, 'datetime_immutable')
            ->setParameter('id', $id)
            ->setParameter('after', $usedAfter, 'datetime_immutable')
            ->execute());
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * @param array<string, array{mixed, ?string}> $parameters value and type by name
     *
     * @return list<array{id: string, tenantId: string, userId: string, name: string, scopes: list<string>, lastFour: string, createdAt: string, expiresAt: string, lastUsedAt: ?string, revokedAt: ?string}>
     */
    private function rows(string $where, array $parameters): array
    {
        /** @var list<array{id: string, tenantId: string, userId: string, name: string, scopes: list<string>, lastFour: string, createdAt: string, expiresAt: string, lastUsedAt: ?string, revokedAt: ?string}> $rows */
        $rows = $this->bypass->run(function () use ($where, $parameters): array {
            $query = $this->entityManager->createQuery(self::RECORD.$where);
            foreach ($parameters as $name => [$value, $type]) {
                $query->setParameter($name, $value, $type);
            }
            /** @var list<array<string, mixed>> $result */
            $result = $query->getArrayResult();

            return array_map(static fn (array $row): array => array_map(self::scalar(...), $row), $result);
        });

        return $rows;
    }

    /**
     * @return string|list<string>|null
     */
    private static function scalar(mixed $value): string|array|null
    {
        return match (true) {
            $value instanceof TenantId => $value->toString(),
            $value instanceof \DateTimeImmutable => $value->format(\DATE_ATOM),
            \is_array($value) => array_values(array_map(static fn (mixed $item): string => \is_string($item) ? $item : '', $value)),
            null === $value => null,
            default => \is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * @param array{id: string, tenantId: string, userId: string, name: string, scopes: list<string>, lastFour: string, createdAt: string, expiresAt: string, lastUsedAt: ?string, revokedAt: ?string} $row
     */
    private function record(array $row): ApiTokenRecord
    {
        return new ApiTokenRecord(
            $row['id'],
            TenantId::fromString($row['tenantId']),
            $row['userId'],
            $row['name'],
            array_values(array_filter(array_map(ApiScope::tryFrom(...), $row['scopes']))),
            $row['lastFour'],
            new \DateTimeImmutable($row['createdAt']),
            new \DateTimeImmutable($row['expiresAt']),
            null === $row['lastUsedAt'] ? null : new \DateTimeImmutable($row['lastUsedAt']),
            null === $row['revokedAt'] ? null : new \DateTimeImmutable($row['revokedAt']),
        );
    }
}
