<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\Labels;
use Foresi\Work\Domain\Label;

final readonly class DoctrineLabels implements Labels
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function label(string $id): ?Label
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(Label::class)->findOneBy(['id' => $id, 'deletedAt' => null]) : null;
    }

    public function trashed(string $id): ?Label
    {
        if (!Id::isValid($id)) {
            return null;
        }
        $label = $this->entityManager->getRepository(Label::class)->findOneBy(['id' => $id]);

        return true === $label?->isDeleted() ? $label : null;
    }

    /**
     * Compared in PHP: LOWER of SQLite folds ASCII letters only, and an
     * instance has few labels.
     */
    public function named(string $name): ?Label
    {
        $wanted = mb_strtolower(trim($name));
        foreach ($this->all() as $label) {
            if (mb_strtolower($label->name()) === $wanted) {
                return $label;
            }
        }

        return null;
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(Label::class)->findBy(['deletedAt' => null], ['name' => 'ASC', 'id' => 'ASC']);
    }

    public function inTrash(): array
    {
        /* @var list<Label> */
        return $this->entityManager
            ->createQuery('SELECT l FROM '.Label::class.' l WHERE l.deletedAt IS NOT NULL ORDER BY l.deletedAt DESC, l.id DESC')
            ->getResult();
    }

    public function trashedBefore(\DateTimeImmutable $moment): array
    {
        /* @var list<Label> */
        return $this->entityManager
            ->createQuery('SELECT l FROM '.Label::class.' l WHERE l.deletedAt < :moment ORDER BY l.deletedAt ASC, l.id ASC')
            ->setParameter('moment', $moment, Types::DATETIME_IMMUTABLE)
            ->getResult();
    }

    public function add(Label $label): void
    {
        $this->entityManager->persist($label);
    }

    public function remove(Label $label): void
    {
        $this->entityManager->remove($label);
    }
}
