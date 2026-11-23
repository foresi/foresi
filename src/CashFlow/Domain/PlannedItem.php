<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * Money a firm expects to come in or go out that no document carries yet: rent,
 * software, insurance and tax advances, a client not invoiced yet, a one-off
 * purchase. It is kept outside the books and generates nothing there. It falls
 * on one day or repeats on a schedule; single occurrences can be skipped, and
 * the forecast leaves out an item that is not included. The match rule is kept
 * for pairing bank movements with its occurrences later.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_flow_planned_item')]
#[ORM\Index(name: 'idx_cash_flow_planned_item_counterparty', columns: ['counterparty_id'])]
class PlannedItem implements Audited
{
    public const int MAX_NAME = 120;
    public const int MAX_MATCH_ACCOUNT = 50;
    public const int MAX_VARIABLE_SYMBOL = 10;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_NAME)]
    private string $name;

    #[ORM\Column(type: 'string', length: 16, enumType: FlowCategory::class)]
    private FlowCategory $category;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(name: 'counterparty_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Party $counterparty;

    /** The day of a one-off item, or the first day of a recurring one. */
    #[ORM\Column(name: 'first_on', type: 'date_immutable')]
    private \DateTimeImmutable $firstOn;

    #[ORM\Column(name: 'recurrence_unit', type: 'string', length: 8, nullable: true, enumType: RecurrenceUnit::class)]
    private ?RecurrenceUnit $unit = null;

    #[ORM\Column(name: 'recurrence_every', type: 'smallint', nullable: true)]
    private ?int $every = null;

    #[ORM\Column(name: 'last_day_of_month', type: 'boolean')]
    private bool $onLastDayOfMonth = false;

    #[ORM\Column(name: 'recurrence_end', type: 'string', length: 16, nullable: true, enumType: RecurrenceEnd::class)]
    private ?RecurrenceEnd $end = null;

    #[ORM\Column(name: 'end_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endOn = null;

    #[ORM\Column(name: 'end_after', type: 'smallint', nullable: true)]
    private ?int $endAfter = null;

    #[ORM\Column(type: 'boolean')]
    private bool $included;

    /** @var list<string> the days of the skipped occurrences, Y-m-d, in order */
    #[ORM\Column(name: 'skipped_on', type: 'json')]
    private array $skippedOn = [];

    /** How the amount stands to its VAT; none for an item from before VAT could be stated. */
    #[ORM\Column(name: 'vat_mode', type: 'string', length: 8, enumType: PlannedVatMode::class, options: ['default' => 'none'])]
    private PlannedVatMode $vatMode;

    /** The VAT rate in percent with two decimals, such as "21.00"; null without a VAT mode. */
    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $vatRate;

    #[ORM\Embedded(class: MatchRule::class, columnPrefix: 'match_')]
    private MatchRule $matchRule;

    public function __construct(
        #[ORM\Column(type: 'string', length: 3, enumType: FlowDirection::class)]
        private readonly FlowDirection $direction,
        PlannedItemTerms $terms,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->matchRule = MatchRule::none();
        $this->change($terms);
    }

    /**
     * Replaces what the item says. The skipped occurrences stay.
     */
    public function change(PlannedItemTerms $terms): void
    {
        $this->name = $terms->name;
        $this->category = $terms->category;
        $this->amountMinor = $terms->amount->minor();
        $this->currency = $terms->amount->currency();
        $this->counterparty = $terms->counterparty;
        $this->included = $terms->included;
        $this->matchRule = $terms->matchRule;
        $this->vatMode = $terms->vatMode;
        $this->vatRate = $terms->vatRate;
        $this->schedule($terms->firstOn, $terms->recurrence);
    }

    /**
     * Marks one occurrence as not happening. Skipping it twice changes nothing.
     */
    public function skip(\DateTimeImmutable $on): void
    {
        $day = $on->format('Y-m-d');
        if (!\in_array($day, $this->skippedOn, true)) {
            $this->skippedOn[] = $day;
            sort($this->skippedOn);
        }
    }

    /**
     * No occurrence after $on: a recurring item ends on that day.
     */
    public function endOn(\DateTimeImmutable $on): void
    {
        $this->end = RecurrenceEnd::OnDate;
        $this->endOn = $on;
        $this->endAfter = null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function direction(): FlowDirection
    {
        return $this->direction;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function category(): FlowCategory
    {
        return $this->category;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->currency);
    }

    public function vatMode(): PlannedVatMode
    {
        return $this->vatMode;
    }

    /**
     * The VAT rate in percent, such as "21" or "12.5"; null without a VAT mode.
     */
    public function vatRate(): ?string
    {
        return null === $this->vatRate ? null : PlannedItemTerms::rate($this->vatRate);
    }

    /**
     * The flow, the net and the VAT of one occurrence as stated; null when no VAT is stated.
     */
    public function vat(): ?PlannedItemVat
    {
        return PlannedItemVat::of($this->amount(), $this->vatMode, $this->vatRate);
    }

    public function counterparty(): ?Party
    {
        return $this->counterparty;
    }

    public function isIncluded(): bool
    {
        return $this->included;
    }

    public function isRecurring(): bool
    {
        return $this->unit instanceof RecurrenceUnit;
    }

    /**
     * The day of a one-off item, the first occurrence day of a recurring one.
     */
    public function firstOn(): \DateTimeImmutable
    {
        return $this->firstOn;
    }

    /**
     * The schedule of a recurring item, its days at midnight in $zone; null for a one-off item.
     */
    public function recurrence(\DateTimeZone $zone): ?Recurrence
    {
        if (!$this->unit instanceof RecurrenceUnit) {
            return null;
        }

        return new Recurrence(
            self::inZone($this->firstOn, $zone),
            $this->unit,
            (int) $this->every,
            $this->onLastDayOfMonth,
            $this->end ?? RecurrenceEnd::Never,
            $this->endOn instanceof \DateTimeImmutable ? self::inZone($this->endOn, $zone) : null,
            $this->endAfter,
        );
    }

    /**
     * @return list<string> the days of the skipped occurrences, Y-m-d, in order
     */
    public function skippedOn(): array
    {
        return $this->skippedOn;
    }

    public function isSkipped(\DateTimeImmutable $on): bool
    {
        return \in_array($on->format('Y-m-d'), $this->skippedOn, true);
    }

    public function matchRule(): MatchRule
    {
        return $this->matchRule;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The calendar day of $day at midnight in $zone.
     */
    public static function inZone(\DateTimeImmutable $day, \DateTimeZone $zone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-d'), $zone);
    }

    private function schedule(\DateTimeImmutable $firstOn, ?Recurrence $recurrence): void
    {
        $this->firstOn = $firstOn;
        $this->unit = $recurrence?->unit();
        $this->every = $recurrence?->every();
        $this->onLastDayOfMonth = $recurrence?->onLastDayOfMonth() ?? false;
        $this->end = $recurrence?->end();
        $this->endOn = $recurrence?->endOn();
        $this->endAfter = $recurrence?->endAfter();
    }
}
