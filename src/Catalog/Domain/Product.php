<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * Something the firm sells: a service, a licence, a retainer. It has a unit,
 * a price per unit or none (the price is agreed per deal), and a way of
 * billing: once, or every interval of a unit of time, a number of cycles or
 * with no end. It carries no VAT: the document that bills it decides that.
 * An archived product stays for what already copied it and is not offered
 * any more.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product')]
class Product implements Audited
{
    public const int MAX_NAME = 200;
    public const int MAX_DESCRIPTION = 2000;
    /** The same length as the unit of an invoice line, so a product's unit always fits on one. */
    public const int MAX_UNIT = 32;
    public const int MAX_INTERVAL = 99;
    public const int MAX_CYCLES = 999;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_NAME)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'string', length: self::MAX_UNIT)]
    private string $unit;

    #[ORM\Column(name: 'unit_price_minor', type: 'bigint', nullable: true)]
    private ?int $unitPriceMinor;

    #[ORM\Column(name: 'currency', type: 'currency', length: 3, nullable: true)]
    private ?Currency $currency;

    /** A plain string, so a new kind needs no data migration. */
    #[ORM\Column(name: 'billing_kind', type: 'string', length: 16, enumType: BillingKind::class)]
    private BillingKind $billingKind;

    #[ORM\Column(name: 'recurrence_unit', type: 'string', length: 8, nullable: true, enumType: RecurrenceUnit::class)]
    private ?RecurrenceUnit $recurrenceUnit;

    #[ORM\Column(name: 'recurrence_interval', type: 'smallint', nullable: true)]
    private ?int $interval;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $cycles;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(
        ProductTerms $terms,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->change($terms);
    }

    /**
     * Replaces what the product says. Whether it is archived stays.
     */
    public function change(ProductTerms $terms): void
    {
        $this->name = $terms->name;
        $this->description = $terms->description;
        $this->unit = $terms->unit;
        $this->unitPriceMinor = $terms->unitPrice?->minor();
        $this->currency = $terms->unitPrice?->currency();
        $this->billingKind = $terms->billingKind;
        $this->recurrenceUnit = $terms->recurrenceUnit;
        $this->interval = $terms->interval;
        $this->cycles = $terms->cycles;
    }

    /**
     * @return bool whether it was not archived before
     */
    public function archive(\DateTimeImmutable $at): bool
    {
        if ($this->archivedAt instanceof \DateTimeImmutable) {
            return false;
        }
        $this->archivedAt = $at;

        return true;
    }

    /**
     * @return bool whether it was archived before
     */
    public function restore(): bool
    {
        if (!$this->archivedAt instanceof \DateTimeImmutable) {
            return false;
        }
        $this->archivedAt = null;

        return true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function unitPrice(): ?Money
    {
        return null === $this->unitPriceMinor || !$this->currency instanceof Currency ? null : Money::ofMinor($this->unitPriceMinor, $this->currency);
    }

    public function billingKind(): BillingKind
    {
        return $this->billingKind;
    }

    public function recurrenceUnit(): ?RecurrenceUnit
    {
        return $this->recurrenceUnit;
    }

    public function interval(): ?int
    {
        return $this->interval;
    }

    public function cycles(): ?int
    {
        return $this->cycles;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
