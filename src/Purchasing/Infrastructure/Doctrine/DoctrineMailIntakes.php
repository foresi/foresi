<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Purchasing\Application\Mail\MailIntakes;
use Foresi\Purchasing\Domain\MailIntake;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineMailIntakes implements MailIntakes
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(MailIntake $intake): void
    {
        $this->entityManager->persist($intake);
    }

    public function find(string $messageId): ?MailIntake
    {
        return Id::isValid($messageId) ? $this->entityManager->find(MailIntake::class, $messageId) : null;
    }

    public function lock(string $messageId): ?MailIntake
    {
        $intake = $this->find($messageId);
        if ($intake instanceof MailIntake) {
            // Read again under the lock, so a run that waited sees what the one before stored.
            $this->entityManager->refresh($intake, LockMode::PESSIMISTIC_WRITE);
        }

        return $intake;
    }

    public function verdictOf(string $messageId): ?string
    {
        $verdicts = $this->entityManager->createQuery('SELECT i.verdict FROM '.MailIntake::class.' i WHERE i.id = :id')
            ->setParameter('id', $messageId)
            ->getSingleColumnResult();

        return \is_string($verdicts[0] ?? null) ? $verdicts[0] : null;
    }

    public function latestOf(string $ownerUserId, int $limit): array
    {
        return $this->entityManager->createQuery('SELECT i FROM '.MailIntake::class.' i WHERE i.ownerUserId = :owner ORDER BY i.createdAt DESC, i.id DESC')
            ->setParameter('owner', $ownerUserId)
            ->setMaxResults(max(1, $limit))
            ->getResult();
    }

    public function firstDraftsOf(array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT source_mail_id, id FROM received_document WHERE source_mail_id IN (?) ORDER BY created_at, id',
            [$messageIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $first = [];
        foreach ($rows as $row) {
            if (\is_string($row['source_mail_id']) && \is_string($row['id']) && !isset($first[$row['source_mail_id']])) {
                $first[$row['source_mail_id']] = $row['id'];
            }
        }

        return $first;
    }
}
