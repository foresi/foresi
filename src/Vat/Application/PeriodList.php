<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Vat\Contract\PossibleDuplicate;

/**
 * The VAT periods of the firm in a range, always an estimate. When there is
 * nothing to compute, the reason says why: payer_date_missing (the firm is
 * a payer but the day it became one is not stated), not_a_payer or
 * country_not_supported. It carries the warnings about the firm's VAT
 * statuses, the rules nobody has confirmed yet (unverified), what the
 * amounts do not count yet (known gaps) and the document sources that are
 * not installed or could not be read (unavailable sources). It lists the
 * bank movements to or from the tax office that pay no period yet, for a
 * person to place, and the planned items of the category vat that may
 * count the VAT a second time.
 */
final readonly class PeriodList
{
    public const string PAYER_DATE_MISSING = 'payer_date_missing';
    public const string NOT_A_PAYER = 'not_a_payer';
    public const string COUNTRY_NOT_SUPPORTED = 'country_not_supported';

    /** What the amounts of every period leave out until the module counts it. */
    public const array KNOWN_GAPS = ['deduction_before_registration'];

    /**
     * @param list<PeriodView>        $periods            oldest first
     * @param list<string>            $warnings
     * @param list<string>            $unverified
     * @param list<string>            $unavailableSources
     * @param list<string>            $knownGaps
     * @param list<PaymentView>       $unassigned         oldest first
     * @param list<PossibleDuplicate> $duplicates
     */
    public function __construct(
        public ?string $reason,
        public ?string $country,
        public ?string $from,
        public string $to,
        public array $periods,
        public array $warnings,
        public array $unverified,
        public array $unavailableSources = [],
        public array $knownGaps = self::KNOWN_GAPS,
        public array $unassigned = [],
        public array $duplicates = [],
    ) {
    }
}
