<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Domain;

use App\Audit\Domain\Audited;
use App\CashFlow\Contract\FlowCategory;
use App\CashFlow\Contract\FlowDirection;
use App\Instance\Domain\TenantId;
use App\Instance\Domain\TenantOwned;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Shared\Domain\Schedule\Recurrence;
use App\Shared\Domain\Schedule\RecurrenceEnd;
use App\Shared\Domain\Schedule\RecurrenceUnit;
use Doctrine\ORM\Mapping as ORM;

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
#[ORM\Index(name: 'idx_cash_flow_planned_item_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_cash_flow_planned_item_firm', columns: ['legal_entity_id'])]
#[ORM\Index(name: 'idx_cash_flow_planned_item_counterparty', columns: ['counterparty_id'])]
class PlannedItem implements TenantOwned, Audited
{
    public const int MAX_NAME = 120;
    public const int MAX_MATCH_ACCOUNT = 50;
    public const int MAX_VARIABLE_SYMBOL = 10;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

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

    #[ORM\Embedded(class: MatchRule::class, columnPrefix: 'match_')]
    private MatchRule $matchRule;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
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

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
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
