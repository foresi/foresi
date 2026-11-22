<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Catalog\Application\CatalogStore;
use Foresi\Catalog\Domain\Product;
use Foresi\Shared\Domain\Id;

/**
 * The products in Doctrine. A text is looked for in the name and the
 * description, without regard to case.
 */
final readonly class DoctrineCatalogStore implements CatalogStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function product(string $id): ?Product
    {
        return Id::isValid($id) ? $this->entityManager->find(Product::class, $id) : null;
    }

    public function search(string $text, bool $includeArchived): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC');
        if (!$includeArchived) {
            $query->andWhere('p.archivedAt IS NULL');
        }
        if ('' !== $text) {
            $query->andWhere("LOWER(p.name) LIKE :text ESCAPE '!' OR LOWER(p.description) LIKE :text ESCAPE '!'")
                ->setParameter('text', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%');
        }
        /** @var list<Product> $products */
        $products = $query->getQuery()->getResult();

        return $products;
    }

    public function add(Product $product): void
    {
        $this->entityManager->persist($product);
    }
}
