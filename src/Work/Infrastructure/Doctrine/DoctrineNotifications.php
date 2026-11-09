<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Work\Application\Notifications;
use App\Work\Domain\Notification;
use App\Work\Domain\Task;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineNotifications implements Notifications
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function openFor(Membership $recipient, Task $task): ?Notification
    {
        return $this->entityManager->getRepository(Notification::class)->findOneBy(['recipient' => $recipient, 'task' => $task, 'doneAt' => null]);
    }

    public function ofRecipient(string $id, Membership $recipient): ?Notification
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(Notification::class)->findOneBy(['id' => $id, 'recipient' => $recipient]) : null;
    }

    public function unread(Membership $recipient): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy(['recipient' => $recipient, 'readAt' => null, 'doneAt' => null]);
    }

    public function ofTask(Task $task): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy(['task' => $task]);
    }

    public function doneBefore(\DateTimeImmutable $moment): array
    {
        /* @var list<Notification> */
        return $this->entityManager
            ->createQuery('SELECT n FROM '.Notification::class.' n WHERE n.doneAt IS NOT NULL AND n.doneAt < :moment ORDER BY n.doneAt ASC, n.id ASC')
            ->setParameter('moment', $moment, Types::DATETIME_IMMUTABLE)
            ->getResult();
    }

    public function add(Notification $notification): void
    {
        $this->entityManager->persist($notification);
    }

    public function remove(Notification $notification): void
    {
        $this->entityManager->remove($notification);
    }
}
