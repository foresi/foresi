<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pattern an invoice is issued from on every date of its schedule. It is not a
 * document: it has no number, no dates of its own and no amounts. Lines keep the
 * price as typed and the kind of rate, so each occurrence takes the rate of its own
 * tax point. The texts may hold period variables, resolved on each occurrence.
 *
 * nextOccurrence is the date the next invoice is due on; the daily run issues every
 * one up to its today. issuedCount counts the invoices really issued, so an end
 * after a count ignores dates skipped while paused. An ended template stays ended
 * and has no next date. The firm and the currency are fixed at creation. Create
 * and change templates through SaveRecurringInvoice and RecurringLifecycle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recurring_invoice')]
#[ORM\Index(name: 'idx_recurring_invoice_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_recurring_invoice_due', columns: ['tenant_id', 'state', 'next_occurrence'])]
#[ORM\Index(name: 'idx_recurring_invoice_firm', columns: ['legal_entity_id'])]
#[ORM\Index(name: 'idx_recurring_invoice_series', columns: ['series_id'])]
#[ORM\Index(name: 'idx_recurring_invoice_customer', columns: ['customer_id'])]
class RecurringInvoice implements TenantOwned, Audited
{
    public const int MAX_DUE_DAYS = 365;
    public const int MAX_NAME_LENGTH = 120;
    public const int MAX_IMPORTED_FROM_LENGTH = 64;
    public const int MAX_FAILURE_CODE_LENGTH = 64;
    /** The most skipped dates a resume names; a longer pause is not worth listing date by date. */
    public const int MAX_SKIPPED = 100;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    // Goes up with every change, so a form or a run can tell it worked from an older
    // version. Technical, so left out of the audit log.
    #[AuditExcluded]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\ManyToOne(targetEntity: Series::class)]
    #[ORM\JoinColumn(name: 'series_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Series $series;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'RESTRICT')]
    private Party $customer;

    #[ORM\Column(name: 'payment_method', type: 'string', length: 32, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    #[ORM\Column(name: 'bank_account', type: 'string', length: 34, nullable: true)]
    private ?string $bankAccount;

    #[ORM\Column(name: 'constant_symbol', type: 'string', length: Invoice::MAX_CONSTANT_SYMBOL_LENGTH)]
    private string $constantSymbol;

    #[ORM\Column(name: 'order_number', type: 'string', length: Invoice::MAX_ORDER_NUMBER_LENGTH)]
    private string $orderNumber;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH, options: ['default' => ''])]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(name: 'text_before', type: 'text')]
    private string $textBefore;

    #[ORM\Column(name: 'text_after', type: 'text')]
    private string $textAfter;

    #[ORM\Column(type: 'string', length: 2, enumType: DocumentLanguage::class, options: ['fixed' => true])]
    private DocumentLanguage $language;

    #[ORM\Column(name: 'first_issue_on', type: 'date_immutable')]
    private \DateTimeImmutable $firstIssueOn;

    #[ORM\Column(name: 'recurrence_unit', type: 'string', length: 8, enumType: RecurrenceUnit::class)]
    private RecurrenceUnit $unit;

    #[ORM\Column(name: 'recurrence_every', type: 'smallint')]
    private int $every;

    #[ORM\Column(name: 'on_last_day_of_month', type: 'boolean')]
    private bool $onLastDayOfMonth;

    #[ORM\Column(name: 'recurrence_end', type: 'string', length: 16, enumType: RecurrenceEnd::class)]
    private RecurrenceEnd $end;

    #[ORM\Column(name: 'end_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endOn;

    #[ORM\Column(name: 'end_after', type: 'smallint', nullable: true)]
    private ?int $endAfter;

    #[ORM\Column(name: 'tax_point', type: 'string', length: 32, enumType: TaxPointRule::class)]
    private TaxPointRule $taxPoint;

    #[ORM\Column(name: 'due_days', type: 'smallint')]
    private int $dueDays;

    #[ORM\Column(type: 'string', length: 16, enumType: RecurringDelivery::class)]
    private RecurringDelivery $delivery;

    /** A copy of each sent invoice to the firm's contact address, else to the owner. */
    #[ORM\Column(name: 'copy_to_me', type: 'boolean', options: ['default' => true])]
    private bool $copyToMe;

    /** Each sent invoice also to the contact's addresses for a copy. */
    #[ORM\Column(name: 'copy_to_others', type: 'boolean', options: ['default' => true])]
    private bool $copyToOthers;

    #[ORM\Column(type: 'string', length: 16, enumType: RecurringState::class)]
    private RecurringState $state = RecurringState::Active;

    #[ORM\Column(name: 'next_occurrence', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextOccurrence;

    #[ORM\Column(name: 'last_occurrence', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastOccurrence = null;

    #[ORM\Column(name: 'issued_count', type: 'integer')]
    private int $issuedCount = 0;

    #[ORM\Column(name: 'paused_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    // When the last run failed; the code says why and is what the history keeps.
    #[AuditExcluded]
    #[ORM\Column(name: 'last_failure_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFailureAt = null;

    #[ORM\Column(name: 'last_failure_code', type: 'string', length: self::MAX_FAILURE_CODE_LENGTH, nullable: true)]
    private ?string $lastFailureCode = null;

    #[AuditExcluded]
    #[ORM\Column(name: 'last_alert_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAlertOn = null;

    /**
     * @param string|null $owner        the user the template sends on behalf of: whoever created it
     * @param string|null $importedFrom the identifier of the template in the system it was imported from
     *
     * @throws \DomainException for a series of another firm, or an imported identifier too long
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
        private readonly Currency $currency,
        RecurringContent $content,
        #[ORM\Column(name: 'owner_id', type: 'guid', nullable: true)]
        private readonly ?string $owner,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
        bool $paused = false,
        #[ORM\Column(name: 'imported_from', type: 'string', length: self::MAX_IMPORTED_FROM_LENGTH, nullable: true)]
        private readonly ?string $importedFrom = null,
    ) {
        if (null !== $importedFrom && ('' === $importedFrom || mb_strlen($importedFrom) > self::MAX_IMPORTED_FROM_LENGTH)) {
            throw new \DomainException('An imported template names its origin in 1 to 64 characters.');
        }
        $this->id = Id::generate();
        $this->firstIssueOn = $content->recurrence->firstIssueOn();
        $this->endOn = null;
        $this->applyContent($content);
        $recurrence = $content->recurrence;
        $this->nextOccurrence = self::firstOccurrenceFrom($recurrence, 0, $recurrence->firstIssueOn())
            ?? throw new \LogicException('A recurrence always allows its first issue date.');
        if ($paused) {
            $this->state = RecurringState::Paused;
            $this->pausedAt = $createdAt;
        }
    }

    /**
     * The first date of a schedule on or after the calendar day of $from that its end
     * rule still allows once $issued invoices exist; null when it allows none.
     */
    public static function firstOccurrenceFrom(Recurrence $recurrence, int $issued, \DateTimeImmutable $from): ?\DateTimeImmutable
    {
        // The end rule counts issued invoices, not the dates passed, so it is applied here.
        $open = new Recurrence($recurrence->firstIssueOn(), $recurrence->unit(), $recurrence->every(), $recurrence->onLastDayOfMonth(), RecurrenceEnd::Never, null, null);
        $date = RecurringSchedule::next($open, 0, $from, 1)[0] ?? throw new \LogicException('A schedule that never ends has a next date.');

        return RecurringSchedule::isOver($recurrence, $issued, $date) ? null : $date;
    }

    /**
     * The next date of a schedule that has issued $issued invoices, the last of them on
     * $lastOccurrence: the day after it, or the first issue date when none was issued;
     * null when the end rule allows no more. A schedule the other system restarted on
     * $restartedOn goes on after that day when it is the later one.
     */
    public static function occurrenceAfter(Recurrence $recurrence, int $issued, ?\DateTimeImmutable $lastOccurrence, ?\DateTimeImmutable $restartedOn = null): ?\DateTimeImmutable
    {
        $after = $restartedOn instanceof \DateTimeImmutable ? max($restartedOn, $lastOccurrence ?? $restartedOn) : $lastOccurrence;

        return self::firstOccurrenceFrom($recurrence, $issued, $after instanceof \DateTimeImmutable ? $after->modify('+1 day') : $recurrence->firstIssueOn());
    }

    /**
     * Carries over what the system it was imported from issued: the count and the last
     * date, so an end after a count and the next date go on from there. A template whose
     * end rule allows no more is ended on its last date. A schedule the other system
     * restarted on $restartedOn goes on after that day.
     *
     * @throws \DomainException unless the template is imported and has issued nothing here, or for a negative count
     */
    public function carryOver(int $issued, ?\DateTimeImmutable $lastOccurrence, ?\DateTimeImmutable $restartedOn = null): void
    {
        if (null === $this->importedFrom || 0 !== $this->issuedCount || $this->lastOccurrence instanceof \DateTimeImmutable) {
            throw new \DomainException('Only an imported template that issued nothing here carries over what it issued.');
        }
        if ($issued < 0) {
            throw new \DomainException('An imported template issued no invoices or more.');
        }
        $this->issuedCount = $issued;
        $this->lastOccurrence = $lastOccurrence instanceof \DateTimeImmutable ? new \DateTimeImmutable($this->day($lastOccurrence)) : null;
        $this->nextOccurrence = self::occurrenceAfter($this->recurrence(), $issued, $this->lastOccurrence, $restartedOn);
        if (!$this->nextOccurrence instanceof \DateTimeImmutable) {
            $this->state = RecurringState::Ended;
            $this->endedAt = $this->lastOccurrence ?? $this->createdAt;
            $this->pausedAt = null;
        }
    }

    /**
     * The next date this template would have under another schedule, edited on $today:
     * never one already issued, and a date the run has not caught up with stays.
     */
    public function nextOccurrenceUnder(Recurrence $recurrence, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $from = $this->day($today);
        if ($this->nextOccurrence instanceof \DateTimeImmutable && $this->day($this->nextOccurrence) < $from) {
            $from = $this->day($this->nextOccurrence);
        }
        if ($this->lastOccurrence instanceof \DateTimeImmutable && $this->day($this->lastOccurrence->modify('+1 day')) > $from) {
            $from = $this->day($this->lastOccurrence->modify('+1 day'));
        }

        return self::firstOccurrenceFrom($recurrence, $this->issuedCount, new \DateTimeImmutable($from));
    }

    /**
     * Issued invoices stay as they are; the next date follows the new schedule.
     *
     * @throws \DomainException once ended, for a series of another firm, or when the new
     *                          schedule leaves no date
     */
    public function change(RecurringContent $content, \DateTimeImmutable $today): void
    {
        $this->refuseEnded();
        $next = $this->nextOccurrenceUnder($content->recurrence, $today)
            ?? throw new \DomainException('The schedule leaves no date to issue on.');
        $this->applyContent($content);
        $this->nextOccurrence = $this->keepDay($this->nextOccurrence, $next);
        ++$this->version;
    }

    /**
     * Records that the invoice of $occurrence was issued: the count goes up and the
     * next date follows, or the template ends when its end rule allows no more.
     *
     * @throws \DomainException unless the template is active and $occurrence is its next date
     */
    public function advance(\DateTimeImmutable $occurrence): void
    {
        if (RecurringState::Active !== $this->state) {
            throw new \DomainException('Only an active template issues invoices.');
        }
        if (!$this->nextOccurrence instanceof \DateTimeImmutable || $this->day($occurrence) !== $this->day($this->nextOccurrence)) {
            throw new \DomainException('A template issues its next date only.');
        }
        ++$this->issuedCount;
        $this->lastOccurrence = $this->nextOccurrence;
        $this->nextOccurrence = self::firstOccurrenceFrom($this->recurrence(), $this->issuedCount, $this->nextOccurrence->modify('+1 day'));
        if (!$this->nextOccurrence instanceof \DateTimeImmutable) {
            $this->state = RecurringState::Ended;
            // The day of its last invoice: the end rule, not a person, ended it.
            $this->endedAt = $this->lastOccurrence;
        }
        ++$this->version;
    }

    /**
     * Repeatable: the first moment stays.
     *
     * @throws \DomainException once ended
     */
    public function pause(\DateTimeImmutable $at): void
    {
        $this->refuseEnded();
        if (RecurringState::Paused === $this->state) {
            return;
        }
        $this->state = RecurringState::Paused;
        $this->pausedAt = $at;
        ++$this->version;
    }

    /**
     * Starts again from the first date on or after $today; what fell in the pause is
     * not issued. Resuming an active template does nothing.
     *
     * @return list<\DateTimeImmutable> the dates skipped, oldest first, at most MAX_SKIPPED
     *
     * @throws \DomainException once ended
     */
    public function resume(\DateTimeImmutable $today): array
    {
        $this->refuseEnded();
        if (RecurringState::Paused !== $this->state) {
            return [];
        }
        $recurrence = $this->recurrence();
        $day = $this->day($today);
        $skipped = [];
        if ($this->nextOccurrence instanceof \DateTimeImmutable && $this->day($this->nextOccurrence) < $day) {
            $open = new Recurrence($recurrence->firstIssueOn(), $recurrence->unit(), $recurrence->every(), $recurrence->onLastDayOfMonth(), RecurrenceEnd::Never, null, null);
            foreach (RecurringSchedule::next($open, $this->issuedCount, $this->nextOccurrence, self::MAX_SKIPPED) as $date) {
                if ($date->format('Y-m-d') >= $day || RecurringSchedule::isOver($recurrence, $this->issuedCount, $date)) {
                    break;
                }
                $skipped[] = $date;
            }
        }
        $from = $day;
        if ($this->lastOccurrence instanceof \DateTimeImmutable && $this->day($this->lastOccurrence->modify('+1 day')) > $from) {
            $from = $this->day($this->lastOccurrence->modify('+1 day'));
        }
        $this->nextOccurrence = self::firstOccurrenceFrom($recurrence, $this->issuedCount, new \DateTimeImmutable($from));
        $this->pausedAt = null;
        if ($this->nextOccurrence instanceof \DateTimeImmutable) {
            $this->state = RecurringState::Active;
        } else {
            $this->state = RecurringState::Ended;
            $this->endedAt = $today;
        }
        ++$this->version;

        return $skipped;
    }

    /**
     * For good: an ended template is never resumed. Repeatable.
     */
    public function end(\DateTimeImmutable $at): void
    {
        if (RecurringState::Ended === $this->state) {
            return;
        }
        $this->state = RecurringState::Ended;
        $this->endedAt = $at;
        $this->pausedAt = null;
        $this->nextOccurrence = null;
        ++$this->version;
    }

    /**
     * A run could not issue or send; the next date stays, so the next run tries again.
     *
     * @throws \DomainException for an empty or overlong code
     */
    public function recordFailure(\DateTimeImmutable $at, string $code): void
    {
        if ('' === $code || \strlen($code) > self::MAX_FAILURE_CODE_LENGTH) {
            throw new \DomainException('A failure has a code of 1 to 64 characters.');
        }
        $this->lastFailureAt = $at;
        $this->lastFailureCode = $code;
    }

    public function clearFailure(): void
    {
        $this->lastFailureAt = null;
        $this->lastFailureCode = null;
    }

    /**
     * @param \DateTimeImmutable $day the tenant's day an alert about this template went out
     */
    public function markAlerted(\DateTimeImmutable $day): void
    {
        $this->lastAlertOn = $this->keepDay($this->lastAlertOn, new \DateTimeImmutable($this->day($day)));
    }

    /**
     * Imported and not touched since: not edited, resumed, paused again or ended
     * here, so it issued nothing here and may be taken back with its import.
     */
    public function isUntouchedImport(): bool
    {
        return null !== $this->importedFrom && 1 === $this->version;
    }

    public function isIssued(): bool
    {
        return $this->issuedCount > 0;
    }

    public function recurrence(): Recurrence
    {
        return new Recurrence($this->firstIssueOn, $this->unit, $this->every, $this->onLastDayOfMonth, $this->end, $this->endOn, $this->endAfter);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function series(): ?Series
    {
        return $this->series;
    }

    public function customer(): Party
    {
        return $this->customer;
    }

    public function paymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function bankAccount(): ?string
    {
        return $this->bankAccount;
    }

    public function constantSymbol(): string
    {
        return $this->constantSymbol;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    /**
     * What the template is called in the list, as iDoklad's "Název vzoru".
     */
    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function textBefore(): string
    {
        return $this->textBefore;
    }

    public function textAfter(): string
    {
        return $this->textAfter;
    }

    public function language(): DocumentLanguage
    {
        return $this->language;
    }

    public function taxPoint(): TaxPointRule
    {
        return $this->taxPoint;
    }

    public function dueDays(): int
    {
        return $this->dueDays;
    }

    public function delivery(): RecurringDelivery
    {
        return $this->delivery;
    }

    public function copyToMe(): bool
    {
        return $this->copyToMe;
    }

    public function copyToOthers(): bool
    {
        return $this->copyToOthers;
    }

    public function state(): RecurringState
    {
        return $this->state;
    }

    public function nextOccurrence(): ?\DateTimeImmutable
    {
        return $this->nextOccurrence;
    }

    public function lastOccurrence(): ?\DateTimeImmutable
    {
        return $this->lastOccurrence;
    }

    public function issuedCount(): int
    {
        return $this->issuedCount;
    }

    public function pausedAt(): ?\DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function endedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function lastFailureAt(): ?\DateTimeImmutable
    {
        return $this->lastFailureAt;
    }

    public function lastFailureCode(): ?string
    {
        return $this->lastFailureCode;
    }

    public function lastAlertOn(): ?\DateTimeImmutable
    {
        return $this->lastAlertOn;
    }

    public function owner(): ?string
    {
        return $this->owner;
    }

    public function importedFrom(): ?string
    {
        return $this->importedFrom;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function applyContent(RecurringContent $content): void
    {
        if ($content->series instanceof Series && $content->series->firm()->id() !== $this->firm->id()) {
            throw new \DomainException('A template takes a series of its own firm.');
        }
        if ($content->dueDays < 0 || $content->dueDays > self::MAX_DUE_DAYS) {
            throw new \DomainException(\sprintf('An invoice is due 0 to %d days after it is issued.', self::MAX_DUE_DAYS));
        }
        $recurrence = $content->recurrence;
        $this->series = $content->series;
        $this->customer = $content->customer;
        $this->paymentMethod = $content->paymentMethod;
        $this->bankAccount = $content->bankAccount;
        $this->constantSymbol = $content->constantSymbol;
        $this->orderNumber = $content->orderNumber;
        $this->description = $content->description;
        $this->name = $this->nameOf($content);
        $this->textBefore = $content->textBefore;
        $this->textAfter = $content->textAfter;
        $this->language = $content->language;
        // A new object for the same day would count as a change and reach the audit log.
        $this->firstIssueOn = $this->keepDay($this->firstIssueOn, $recurrence->firstIssueOn());
        $this->unit = $recurrence->unit();
        $this->every = $recurrence->every();
        $this->onLastDayOfMonth = $recurrence->onLastDayOfMonth();
        $this->end = $recurrence->end();
        $endOn = $recurrence->endOn();
        $this->endOn = $endOn instanceof \DateTimeImmutable ? $this->keepDay($this->endOn, $endOn) : null;
        $this->endAfter = $recurrence->endAfter();
        $this->taxPoint = $content->taxPoint;
        $this->dueDays = $content->dueDays;
        $this->delivery = $content->delivery;
        $this->copyToMe = $content->copyToMe;
        $this->copyToOthers = $content->copyToOthers;
    }

    /**
     * The name as given; without one, the description, else the customer, as the
     * templates made before names existed were named.
     *
     * @throws \DomainException for a name over MAX_NAME_LENGTH characters
     */
    private function nameOf(RecurringContent $content): string
    {
        $name = trim($content->name);
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \DomainException(\sprintf('A template is named in at most %d characters.', self::MAX_NAME_LENGTH));
        }
        if ('' === $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $content->description) ?? '');
        }

        return mb_substr('' !== $name ? $name : $content->customer->displayName(), 0, self::MAX_NAME_LENGTH);
    }

    private function keepDay(?\DateTimeImmutable $stored, \DateTimeImmutable $given): \DateTimeImmutable
    {
        return $stored instanceof \DateTimeImmutable && $this->day($stored) === $this->day($given) ? $stored : $given;
    }

    private function day(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    private function refuseEnded(): void
    {
        if (RecurringState::Ended === $this->state) {
            throw new \DomainException('An ended template stays ended.');
        }
    }
}
