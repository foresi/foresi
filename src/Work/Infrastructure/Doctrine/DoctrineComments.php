<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\Comments;
use Foresi\Work\Domain\CommentMention;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskComment;

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

    public function remove(TaskComment|CommentMention $item): void
    {
        $this->entityManager->remove($item);
    }
}
