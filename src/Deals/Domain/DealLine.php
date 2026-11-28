<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Domain;

use Brick\Math\BigDecimal;
use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * Something the deal sells: a name, a unit, a quantity, a price per unit or
 * none, and how it is billed. A line added from a product copies what the
 * product says at that moment and keeps the product's id only to show where
 * it came from: a later change to the product never changes the line.
 *
 * An hourly (time-based) line may link to one project of Work, whose billable
 * time it bills at its rate; a project serves at most one line.
 */
#[ORM\Entity]
#[ORM\Table(name: 'deal_line')]
#[ORM\Index(name: 'idx_deal_line_deal', columns: ['deal_id'])]
#[ORM\UniqueConstraint(name: 'uniq_deal_line_project', columns: ['project_id'])]
class DealLine implements Audited
{
    public const int MAX_NAME = 200;
    /** The same length as the unit of a product and of an invoice line. */
    public const int MAX_UNIT = 32;
    public const int MAX_INTERVAL = 99;
    public const int MAX_CYCLES = 999;
    public const int QUANTITY_SCALE = 3;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_NAME)]
    private string $name;

    #[ORM\Column(type: 'string', length: self::MAX_UNIT)]
    private string $unit;

    #[ORM\Column(type: 'decimal', precision: 15, scale: self::QUANTITY_SCALE, nullable: true)]
    private ?string $quantity;

    #[ORM\Column(name: 'unit_price_minor', type: 'bigint', nullable: true)]
    private ?int $unitPriceMinor;

    #[ORM\Column(name: 'currency', type: 'currency', length: 3, nullable: true)]
    private ?Currency $currency;

    #[ORM\Column(name: 'billing_kind', type: 'string', length: 16, enumType: BillingKind::class)]
    private BillingKind $billingKind;

    #[ORM\Column(name: 'recurrence_unit', type: 'string', length: 8, nullable: true, enumType: RecurrenceUnit::class)]
    private ?RecurrenceUnit $recurrenceUnit;

    #[ORM\Column(name: 'recurrence_interval', type: 'smallint', nullable: true)]
    private ?int $interval;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $cycles;

    #[ORM\Column(name: 'project_id', type: 'guid', nullable: true)]
    private ?string $projectId = null;

    public function __construct(
        #[ORM\Column(name: 'deal_id', type: 'guid')]
        private readonly string $dealId,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'product_id', type: 'guid', nullable: true)]
        private readonly ?string $productId,
        DealLineTerms $terms,
    ) {
        $this->id = Id::generate();
        $this->change($terms);
    }

    /**
     * Replaces what the line says. The product it came from stays.
     */
    public function change(DealLineTerms $terms): void
    {
        $this->name = $terms->name;
        $this->unit = $terms->unit;
        $this->quantity = $terms->quantity;
        $this->unitPriceMinor = $terms->unitPrice?->minor();
        $this->currency = $terms->unitPrice?->currency();
        $this->billingKind = $terms->billingKind;
        $this->recurrenceUnit = $terms->recurrenceUnit;
        $this->interval = $terms->interval;
        $this->cycles = $terms->cycles;
    }

    /**
     * Links the line to a project of Work, or unlinks it with null. Checked by
     * Foresi\Deals\Application\DealLineWrites: only an hourly line has one.
     */
    public function linkProject(?string $projectId): void
    {
        $this->projectId = $projectId;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    /**
     * The same line for another deal, such as a follow-up: what it says is
     * copied, not the project, which serves one line only.
     */
    public function copyFor(string $dealId, int $position): self
    {
        return new self($dealId, $position, $this->productId, $this->terms());
    }

    public function terms(): DealLineTerms
    {
        return new DealLineTerms($this->name, $this->unit, $this->quantity(), $this->unitPrice(), $this->billingKind, $this->recurrenceUnit, $this->interval, $this->cycles);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function dealId(): string
    {
        return $this->dealId;
    }

    public function position(): int
    {
        return $this->position;
    }

    /** The product it was copied from, or null for a line typed in. */
    public function productId(): ?string
    {
        return $this->productId;
    }

    /** The project of Work whose billable time the line bills; null for none. */
    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    /** A decimal with three places, such as "1.500", whatever the database hands back; null for a time-based line with no estimate. */
    public function quantity(): ?string
    {
        if (null === $this->quantity) {
            return null;
        }

        return (string) BigDecimal::of($this->quantity)->toScale(self::QUANTITY_SCALE);
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
}
