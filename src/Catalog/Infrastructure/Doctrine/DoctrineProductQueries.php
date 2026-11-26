<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Catalog\Application\ProductQueries;
use Foresi\Catalog\Application\View\ProductLists;
use Foresi\Catalog\Contract\Field\ProductExtension;
use Foresi\Catalog\Domain\Product;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Infrastructure\View\DqlConditions;

/**
 * The products of a view in DQL. The billing kind is its column; archived is
 * a moment, compiled as a boolean.
 */
final readonly class DoctrineProductQueries implements ProductQueries
{
    private const array COLUMNS = [ProductLists::BILLING_KIND => 'p.billingKind'];

    public function __construct(private EntityManagerInterface $entityManager, private ProductLists $lists, private InstanceClock $clock, private Fields $fields)
    {
    }

    public function index(ViewFilter $filter, bool $descending = false, string $text = ''): array
    {
        $derived = static fn (Condition $condition): array => [match ($condition->field) {
            ProductLists::ARCHIVED => DqlConditions::derivedBoolean($condition, 'p.archivedAt IS NOT NULL'),
            default => throw new \LogicException(\sprintf('The field "%s" of the products has no SQL.', $condition->field)),
        }, []];
        [$condition, $parameters] = new DqlConditions($this->lists->list(), self::COLUMNS, $derived(...))->compile($filter, $this->clock->today(...), 'v');
        // The archived products only when the view asks for them.
        $archived = [] === $filter->on(ProductLists::ARCHIVED) ? 'p.archivedAt IS NULL AND ' : '';
        $direction = $descending ? 'DESC' : 'ASC';
        $found = '' === $text ? '' : " AND (LOWER(p.name) LIKE :text ESCAPE '!' OR LOWER(p.description) LIKE :text ESCAPE '!'"
            .implode('', array_map(static fn (string $field): string => ' OR '.$field, $this->fields->searched(ProductExtension::RECORD, 'p', 'text'))).')';
        $query = $this->entityManager->createQuery('SELECT p FROM '.Product::class.' p WHERE '.$archived.$condition.$found.' ORDER BY p.name '.$direction.', p.id '.$direction);
        if ('' !== $text) {
            $query->setParameter('text', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%');
        }
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }
        /** @var list<Product> $products */
        $products = $query->getResult();
        // The custom fields of the rows, read once for their columns (ADR 0045).
        $this->fields->values(ProductExtension::RECORD, array_map(static fn (Product $product): string => $product->id(), $products));

        return $products;
    }
}
