<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Contract\Event\ProductArchived;
use Foresi\Catalog\Contract\Event\ProductCreated;
use Foresi\Catalog\Contract\Event\ProductRestored;
use Foresi\Catalog\Contract\Event\ProductUpdated;
use Foresi\Catalog\Domain\Product;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Event\EventRecorder;

/**
 * The writes of the catalog, from its screens, the API and MCP alike (ADR 0032).
 * Each runs in one unit of work with its catalog.product event, and only the
 * owners and members of the organisation write. Archiving an archived product,
 * or restoring one that is not, changes nothing and records nothing.
 */
final readonly class ProductWrites
{
    public function __construct(
        private CatalogAccess $access,
        private ProductRules $rules,
        private CatalogStore $store,
        private CatalogWork $work,
        private EventRecorder $events,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @throws CatalogRefused
     */
    public function create(CreateProductInput $input): Product
    {
        $this->access->denyUnlessStaff();
        $terms = $this->rules->terms(new ProductDraft($input->name, $input->description, $input->unit, $input->unitPrice, $input->currency, $input->billingKind, $input->recurrenceUnit, $input->interval, $input->cycles));

        return $this->work->run(function () use ($terms): Product {
            $product = new Product($terms, $this->now());
            $this->store->add($product);
            $this->events->record(new ProductCreated($product->id(), $product->billingKind()));

            return $product;
        });
    }

    /**
     * @throws CatalogRefused
     */
    public function update(UpdateProductInput $input): Product
    {
        return $this->work->run(function () use ($input): Product {
            $product = $this->product($input->productId);
            $kind = self::given($input->billingKind, $product->billingKind()->value);
            // A product made one-off keeps no recurrence, unless the input sends one, which is refused.
            $recurs = BillingKind::Recurring->value === $kind;
            $price = $product->unitPrice();
            $product->change($this->rules->terms(new ProductDraft(
                self::given($input->name, $product->name()),
                self::given($input->description, $product->description()),
                self::given($input->unit, $product->unit()),
                self::given($input->unitPrice, null === $price ? null : self::decimal($price->minor())),
                self::given($input->currency, $price?->currency()->value ?? 'CZK'),
                $kind,
                self::given($input->recurrenceUnit, $recurs ? $product->recurrenceUnit()?->value : null),
                self::given($input->interval, $recurs ? $product->interval() : null),
                self::given($input->cycles, $recurs ? $product->cycles() : null),
            )));
            $this->events->record(new ProductUpdated($product->id(), $product->billingKind()));

            return $product;
        });
    }

    /**
     * @throws CatalogRefused
     */
    public function archive(ArchiveProductInput $input): Product
    {
        return $this->work->run(function () use ($input): Product {
            $product = $this->product($input->productId);
            if ($product->archive($this->now())) {
                $this->events->record(new ProductArchived($product->id(), $product->billingKind()));
            }

            return $product;
        });
    }

    /**
     * @throws CatalogRefused
     */
    public function restore(RestoreProductInput $input): Product
    {
        return $this->work->run(function () use ($input): Product {
            $product = $this->product($input->productId);
            if ($product->restore()) {
                $this->events->record(new ProductRestored($product->id(), $product->billingKind()));
            }

            return $product;
        });
    }

    /**
     * @throws CatalogRefused forbidden, not_found
     */
    private function product(string $id): Product
    {
        $this->access->denyUnlessStaff();

        return $this->store->product($id) ?? throw CatalogRefused::notFound();
    }

    /**
     * The moment of a write, from the instance's clock, kept in UTC as every stored moment.
     */
    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->clock()->now())->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @template T
     *
     * @param T|Omitted $value
     * @param T         $current
     *
     * @return T
     */
    private static function given(mixed $value, mixed $current): mixed
    {
        return $value instanceof Omitted ? $current : $value;
    }

    /**
     * Minor units as the decimal the rules read back, such as "1500.50".
     */
    private static function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', \STR_PAD_LEFT);
    }
}
