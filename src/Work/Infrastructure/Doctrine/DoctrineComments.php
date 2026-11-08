<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Shared\Domain\Id;
use App\Work\Application\Comments;
use App\Work\Domain\CommentMention;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineComments implements Comments
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function comment(string $id): ?TaskComment
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(TaskComment::class)->findOneBy(['id' => $id]) : null;
    }

    public function of(Task $task): array
    {
        /* @var list<TaskComment> */
        return $this->entityManager
            ->createQuery('SELECT c, a, u FROM '.TaskComment::class.' c JOIN c.author a JOIN a.user u WHERE c.task = :task ORDER BY c.createdAt ASC, c.id ASC')
            ->setParameter('task', $task->id())
            ->getResult();
    }

    public function mentions(TaskComment $comment): array
    {
        return $this->entityManager->getRepository(CommentMention::class)->findBy(['comment' => $comment]);
    }

    public function add(TaskComment|CommentMention $item): void
    {
        $this->entityManager->persist($item);
    }

    public function remove(CommentMention $mention): void
    {
        $this->entityManager->remove($mention);
    }
}
