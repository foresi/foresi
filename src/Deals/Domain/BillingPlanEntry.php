<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Deals\Contract\BillingDocumentType;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

/**
 * One planned invoice of a won deal (the billing plan, as Productive plans
 * the invoices of a budget). A one-off entry is an amount, invoiced once; a
 * recurring entry follows one recurring line of the deal and takes its amount
 * and period from it, so it stores no amount. The day it is expected to be
 * invoiced on may be empty: then nothing assumes one, and the forecast shows
 * it apart, without a day.
 *
 * Once an issued invoice or a recurring invoice fulfils it, the forecast
 * counts that document and no longer the entry. The link is kept here, by
 * type and id, as Timesheet keeps the invoice of a time entry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'deal_billing_plan_entry')]
#[ORM\Index(name: 'idx_deal_billing_plan_entry_deal', columns: ['deal_id'])]
#[ORM\Index(name: 'idx_deal_billing_plan_entry_fulfilled', columns: ['fulfilled_type', 'fulfilled_id'])]
#[ORM\UniqueConstraint(name: 'uniq_deal_billing_plan_entry_line', columns: ['line_id'])]
class BillingPlanEntry implements Audited
{
    public const int MAX_TEXT = 200;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'amount_minor', type: 'bigint', nullable: true)]
    private ?int $amountMinor = null;

    #[ORM\Column(name: 'currency', type: 'currency', length: 3, nullable: true)]
    private ?Currency $currency = null;

    #[ORM\Column(name: 'expected_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expectedOn = null;

    #[ORM\Column(type: 'string', length: self::MAX_TEXT)]
    private string $text = '';

    #[ORM\Column(name: 'fulfilled_type', type: 'string', length: 32, nullable: true, enumType: BillingDocumentType::class)]
    private ?BillingDocumentType $fulfilledType = null;

    #[ORM\Column(name: 'fulfilled_id', type: 'guid', nullable: true)]
    private ?string $fulfilledId = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param string|null $lineId the recurring line a recurring entry follows; null for a one-off entry
     */
    private function __construct(
        #[ORM\Column(name: 'deal_id', type: 'guid')]
        private readonly string $dealId,
        #[ORM\Column(type: 'string', length: 16, enumType: BillingEntryKind::class)]
        private readonly BillingEntryKind $kind,
        #[ORM\Column(name: 'line_id', type: 'guid', nullable: true)]
        private readonly ?string $lineId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->updatedAt = $createdAt;
    }

    /**
     * @throws InvalidField amount_invalid, text_too_long
     */
    public static function oneOff(string $dealId, Money $amount, ?\DateTimeImmutable $expectedOn, string $text, \DateTimeImmutable $now): self
    {
        $entry = new self($dealId, BillingEntryKind::OneOff, null, $now);
        $entry->setAmount($amount);
        $entry->expectedOn = self::day($expectedOn);
        $entry->text = self::checkedText($text);

        return $entry;
    }

    /**
     * @throws InvalidField text_too_long
     */
    public static function recurring(string $dealId, string $lineId, ?\DateTimeImmutable $expectedOn, string $text, \DateTimeImmutable $now): self
    {
        $entry = new self($dealId, BillingEntryKind::Recurring, $lineId, $now);
        $entry->expectedOn = self::day($expectedOn);
        $entry->text = self::checkedText($text);

        return $entry;
    }

    /**
     * Changes the amount of a one-off entry.
     *
     * @throws InvalidField amount_invalid, amount_not_one_off
     */
    public function changeAmount(Money $amount, \DateTimeImmutable $now): void
    {
        if (BillingEntryKind::OneOff !== $this->kind) {
            throw new InvalidField('amount', 'amount_not_one_off', 'A recurring entry takes its amount from its line; change the line instead.');
        }
        $this->setAmount($amount);
        $this->updatedAt = $now;
    }

    /** The day it is expected to be invoiced on; null for none, when nothing assumes one. */
    public function planOn(?\DateTimeImmutable $expectedOn, \DateTimeImmutable $now): void
    {
        $this->expectedOn = self::day($expectedOn);
        $this->updatedAt = $now;
    }

    /**
     * @throws InvalidField text_too_long
     */
    public function describe(string $text, \DateTimeImmutable $now): void
    {
        $this->text = self::checkedText($text);
        $this->updatedAt = $now;
    }

    /** Links the document that fulfils it, which the forecast then counts instead. */
    public function fulfil(BillingDocumentType $type, string $documentId, \DateTimeImmutable $now): void
    {
        $this->fulfilledType = $type;
        $this->fulfilledId = $documentId;
        $this->updatedAt = $now;
    }

    /**
     * Removes the link, so the entry counts again. Whether there was one.
     */
    public function unfulfil(\DateTimeImmutable $now): bool
    {
        if (!$this->isFulfilled()) {
            return false;
        }
        $this->fulfilledType = null;
        $this->fulfilledId = null;
        $this->updatedAt = $now;

        return true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function dealId(): string
    {
        return $this->dealId;
    }

    public function kind(): BillingEntryKind
    {
        return $this->kind;
    }

    public function lineId(): ?string
    {
        return $this->lineId;
    }

    /** The amount of a one-off entry; null for a recurring one, which its line prices. */
    public function amount(): ?Money
    {
        return null === $this->amountMinor || !$this->currency instanceof Currency ? null : Money::ofMinor($this->amountMinor, $this->currency);
    }

    public function expectedOn(): ?\DateTimeImmutable
    {
        return $this->expectedOn;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilledType instanceof BillingDocumentType && null !== $this->fulfilledId;
    }

    public function fulfilledType(): ?BillingDocumentType
    {
        return $this->fulfilledType;
    }

    public function fulfilledId(): ?string
    {
        return $this->fulfilledId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @throws InvalidField amount_invalid
     */
    private function setAmount(Money $amount): void
    {
        if ($amount->isNegative() || $amount->isZero()) {
            throw new InvalidField('amount', 'amount_invalid', 'A planned invoice is for more than zero.');
        }
        $this->amountMinor = $amount->minor();
        $this->currency = $amount->currency();
    }

    private static function day(?\DateTimeImmutable $day): ?\DateTimeImmutable
    {
        return $day instanceof \DateTimeImmutable ? new \DateTimeImmutable($day->format('Y-m-d')) : null;
    }

    /**
     * @throws InvalidField text_too_long
     */
    private static function checkedText(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new InvalidField('text', 'text_too_long', \sprintf('The text of a planned invoice is at most %d characters.', self::MAX_TEXT));
        }

        return $text;
    }
}
