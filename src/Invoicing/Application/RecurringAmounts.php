<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Money;

/**
 * What the next invoice of a template would come to, priced as an issue prices it:
 * by the firm's regime and rounding, each rate from its kind on the tax point the
 * next date would have. A template has no amount of its own, so a list shows this.
 */
final readonly class RecurringAmounts
{
    public function __construct(
        private LinePricing $pricing,
        private FirmInvoicingSettings $settings,
        private InvoicingCountries $countries,
    ) {
    }

    /**
     * @param list<RecurringInvoice>                    $templates
     * @param array<string, list<RecurringInvoiceLine>> $lines     by template id, as RecurringOverview::prepare() gives them
     * @param \DateTimeImmutable                        $today     the tenant's today, for a template without a next date
     *
     * @return array<string, Money|null> payable by template id; null when it cannot be priced, such as without a rate on that day
     */
    public function payable(array $templates, array $lines, \DateTimeImmutable $today): array
    {
        $rounding = [];
        $amounts = [];
        foreach ($templates as $template) {
            $firm = $template->firm();
            $rounding[$firm->id()] ??= $this->settings->for($firm)->rounding();
            $amounts[$template->id()] = $this->priced($template, $firm, $lines[$template->id()] ?? [], $rounding[$firm->id()], $today);
        }

        return $amounts;
    }

    /**
     * @param list<RecurringInvoiceLine> $lines
     */
    private function priced(RecurringInvoice $template, LegalEntity $firm, array $lines, TotalRounding $rounding, \DateTimeImmutable $today): ?Money
    {
        $inputs = array_map(static fn (RecurringInvoiceLine $line): LineInput => new LineInput(
            $line->text(),
            $line->quantity(),
            $line->unit(),
            $line->price(),
            $line->priceType(),
            $line->treatment(),
            $line->rateKind(),
            $line->supplyCode(),
            $line->discount(),
        ), $lines);
        $regime = VatRegime::of($firm->vatStatus());
        try {
            $country = $this->countries->for($firm->address()->countryCode);
            $priced = $this->pricing->priced($inputs, $regime, $country, $template->taxPoint()->for($template->nextOccurrence() ?? $today));

            return $this->pricing->totals($priced, $regime, $rounding, $country)->payable;
        } catch (UnsupportedCountry|\DomainException|\OverflowException) {
            return null;
        }
    }
}
