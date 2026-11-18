<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\OAuth\AuthorizationCode;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\RefreshToken;

/**
 * The OAuth clients, codes and refresh tokens in the database. A code and a
 * refresh token are claimed with an update that only one request can win.
 */
final readonly class DoctrineOAuthStore implements OAuthStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function addClient(OAuthClient $client): void
    {
        $this->entityManager->persist($client);
    }

    public function clientByClientId(string $clientId): ?OAuthClient
    {
        return $this->entityManager->getRepository(OAuthClient::class)->findOneBy(['clientId' => $clientId]);
    }

    public function client(string $id): ?OAuthClient
    {
        return $this->entityManager->getRepository(OAuthClient::class)->findOneBy(['id' => $id]);
    }

    public function addCode(AuthorizationCode $code): void
    {
        $this->entityManager->persist($code);
    }

    public function codeByHash(string $codeHash): ?AuthorizationCode
    {
        return $this->entityManager->getRepository(AuthorizationCode::class)->findOneBy(['codeHash' => $codeHash]);
    }

    public function claimCode(string $id, \DateTimeImmutable $now): bool
    {
        return 1 === $this->claim(AuthorizationCode::class, $id, $now);
    }

    public function attachToken(string $codeId, string $apiTokenId): void
    {
        $this->entityManager
            ->createQuery('UPDATE '.AuthorizationCode::class.' c SET c.apiTokenId = :token WHERE c.id = :id')
            ->setParameter('token', $apiTokenId)
            ->setParameter('id', $codeId)
            ->execute();
    }

    public function addRefreshToken(RefreshToken $token): void
    {
        $this->entityManager->persist($token);
    }

    public function refreshTokenByHash(string $tokenHash): ?RefreshToken
    {
        return $this->entityManager->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function claimRefreshToken(string $id, \DateTimeImmutable $now): bool
    {
        return 1 === $this->claim(RefreshToken::class, $id, $now);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * @param class-string $entity
     */
    private function claim(string $entity, string $id, \DateTimeImmutable $now): int
    {
        $affected = $this->entityManager
            ->createQuery('UPDATE '.$entity.' e SET e.usedAt = :now WHERE e.id = :id AND e.usedAt IS NULL')
            ->setParameter('now', $now, 'datetime_immutable')
            ->setParameter('id', $id)
            ->execute();

        return \is_int($affected) ? $affected : 0;
    }
}
