<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One amount a source expects to come in or go out on a day, in its own
 * currency. The amount is positive; the direction says which way it goes.
 */
final readonly class ExpectedFlow
{
    /**
     * @param string         $source          the key of the source that gave it
     * @param string         $label           what a person reads, such as a document number or a planned item's name
     * @param list<FlowFlag> $flags
     * @param string|null    $counterpartyId  the contact the money comes from or goes to
     * @param Money|null     $vat             the VAT the amount holds, in its currency; null when none is stated
     * @param Money|null     $selfAssessedVat the VAT the firm as the recipient self-assesses on top of the amount (reverse charge), in its currency; null when none
     */
    public function __construct(
        public string $source,
        public FlowSubject $subject,
        public FlowDirection $direction,
        public Money $amount,
        public \DateTimeImmutable $expectedOn,
        public FlowCategory $category,
        public FlowCertainty $certainty,
        public string $label,
        public array $flags = [],
        public ?string $counterpartyId = null,
        public ?Money $vat = null,
        public ?Money $selfAssessedVat = null,
    ) {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException('An expected flow has a positive amount; its direction says which way it goes.');
        }
        if ($vat instanceof Money && ($vat->isNegative() || $vat->currency() !== $amount->currency())) {
            throw new \InvalidArgumentException('The VAT of an expected flow is zero or more, in the currency of its amount.');
        }
        if ($selfAssessedVat instanceof Money && ($selfAssessedVat->isNegative() || $selfAssessedVat->currency() !== $amount->currency())) {
            throw new \InvalidArgumentException('The self-assessed VAT of an expected flow is zero or more, in the currency of its amount.');
        }
    }

    public function hasFlag(FlowFlag $flag): bool
    {
        return \in_array($flag, $this->flags, true);
    }

    /**
     * The same flow on another day, with a flag that says why it moved.
     */
    public function movedTo(\DateTimeImmutable $on, FlowFlag $flag): self
    {
        $flags = $this->hasFlag($flag) ? $this->flags : [...$this->flags, $flag];

        return new self($this->source, $this->subject, $this->direction, $this->amount, $on, $this->category, $this->certainty, $this->label, $flags, $this->counterpartyId, $this->vat, $this->selfAssessedVat);
    }
}
