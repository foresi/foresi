<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatStretch;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The VAT rules of one country (ADR 0033: the VAT module owns the contract,
 * a country module implements it): which calendar period holds a day, when
 * a period's VAT is due and its refund expected, how the tax office is paid,
 * and what in the firm's VAT statuses the country warns about. The module
 * uses the rules of the firm's country, and computes nothing without them.
 */
#[AutoconfigureTag(self::TAG)]
interface VatCountryRules
{
    public const string TAG = 'app.vat_country_rules';

    /**
     * The ISO 3166-1 alpha-2 code of the country, such as "CZ".
     */
    public function country(): string;

    /**
     * The whole calendar month or quarter that holds the day.
     */
    public function periodOf(\DateTimeImmutable $day, VatPeriodLength $length): VatPeriod;

    /**
     * The day the return and the payment of the whole period are due.
     */
    public function dueOn(VatPeriod $period): \DateTimeImmutable;

    /**
     * The day a refund of the whole period is expected, at the earliest.
     */
    public function refundExpectedOn(VatPeriod $period): \DateTimeImmutable;

    /**
     * How the firm with this VAT ID pays its VAT.
     */
    public function suggestedPayment(?string $vatId): PaymentSuggestion;

    /**
     * The codes of what the country does not allow in the stretches, such as
     * "quarter_not_allowed". They are warnings only: the stretches are kept.
     *
     * @param list<VatStretch> $stretches oldest first, the undated one first
     *
     * @return list<string>
     */
    public function warnings(array $stretches): array;

    /**
     * The codes of the rules nobody has confirmed from a primary source yet,
     * such as "refund_timing", which every answer carries until they are.
     *
     * @return list<string>
     */
    public function unverified(): array;
}
