<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

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
        return Id::isValid($id) ? $this->entityManager->getRepository(Label::class)->findOneBy(['id' => $id]) : null;
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
        return $this->entityManager->getRepository(Label::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
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
