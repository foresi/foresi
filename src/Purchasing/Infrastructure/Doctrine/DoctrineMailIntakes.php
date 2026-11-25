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
use Foresi\Purchasing\Domain\ReceivedDocument;
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

    public function draftsOf(string $messageId): int
    {
        return (int) $this->entityManager->createQuery('SELECT COUNT(d.id) FROM '.ReceivedDocument::class.' d WHERE d.sourceMailId = :mail')
            ->setParameter('mail', $messageId)
            ->getSingleScalarResult();
    }
}
