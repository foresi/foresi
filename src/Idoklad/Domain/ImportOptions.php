<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/**
 * What the person chose for an import before it runs: the day from which the
 * firm was a VAT payer, null when it never was. A document whose tax point (or
 * issue date, without one) is on or after that day is a payer's document;
 * iDoklad's own field cannot say it, see the documents stage.
 */
final readonly class ImportOptions
{
    public function __construct(public ?\DateTimeImmutable $vatPayerFrom)
    {
    }

    /**
     * @param array<mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $from = \is_string($stored['vat_payer_from'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $stored['vat_payer_from']) : false;

        return new self(false === $from ? null : $from);
    }

    /**
     * @return array{vat_payer_from: string|null}
     */
    public function toArray(): array
    {
        return ['vat_payer_from' => $this->vatPayerFrom?->format('Y-m-d')];
    }

    /** Whether a document of that day is a payer's; one without a day is not. */
    public function isPayerOn(?\DateTimeImmutable $day): bool
    {
        return $this->vatPayerFrom instanceof \DateTimeImmutable && $day instanceof \DateTimeImmutable && $day->format('Y-m-d') >= $this->vatPayerFrom->format('Y-m-d');
    }
}
