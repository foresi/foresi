<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\DocumentTotals;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Domain\Money;

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
        private InstanceFirm $instanceFirm,
    ) {
    }

    /**
     * @param list<RecurringInvoice>                    $templates
     * @param array<string, list<RecurringInvoiceLine>> $lines     by template id, as RecurringOverview::prepare() gives them
     * @param \DateTimeImmutable                        $today     the instance's today, for a template without a next date
     *
     * @return array<string, Money|null> payable by template id; null when it cannot be priced, such as without a rate on that day
     */
    public function payable(array $templates, array $lines, \DateTimeImmutable $today): array
    {
        if ([] === $templates) {
            return [];
        }
        // Read once for the whole page: a query per template would grow the list's queries with its rows.
        $firm = $this->instanceFirm->get();
        $rounding = $this->settings->get()->rounding();
        $amounts = [];
        foreach ($templates as $template) {
            $amounts[$template->id()] = $this->priced($template, $firm, $lines[$template->id()] ?? [], $rounding, $today);
        }

        return $amounts;
    }

    /**
     * The VAT of the next invoice of each template, in its currency, as the
     * VAT module counts the occurrences not issued yet.
     *
     * @param list<RecurringInvoice>                    $templates
     * @param array<string, list<RecurringInvoiceLine>> $lines     by template id, as RecurringOverview::prepare() gives them
     *
     * @return array<string, Money|null> VAT by template id; null when it cannot be priced
     */
    public function vat(array $templates, array $lines, \DateTimeImmutable $today): array
    {
        $firm = $this->instanceFirm->get();
        $rounding = $this->settings->get()->rounding();
        $amounts = [];
        foreach ($templates as $template) {
            $amounts[$template->id()] = $this->price($template, $firm, $lines[$template->id()] ?? [], $rounding, $today)['totals']->vat ?? null;
        }

        return $amounts;
    }

    /**
     * The lines and totals of the next invoice, for a template shown on its own.
     *
     * @param list<RecurringInvoiceLine> $lines
     *
     * @return array{lines: list<PricedLine>, totals: DocumentTotals}|null null when it cannot be priced
     */
    public function breakdown(RecurringInvoice $template, array $lines, \DateTimeImmutable $today): ?array
    {
        return $this->price($template, $this->instanceFirm->get(), $lines, $this->settings->get()->rounding(), $today);
    }

    /**
     * @param list<RecurringInvoiceLine> $lines
     */
    private function priced(RecurringInvoice $template, LegalEntity $firm, array $lines, TotalRounding $rounding, \DateTimeImmutable $today): ?Money
    {
        return $this->price($template, $firm, $lines, $rounding, $today)['totals']->payable ?? null;
    }

    /**
     * @param list<RecurringInvoiceLine> $lines
     *
     * @return array{lines: list<PricedLine>, totals: DocumentTotals}|null
     */
    private function price(RecurringInvoice $template, LegalEntity $firm, array $lines, TotalRounding $rounding, \DateTimeImmutable $today): ?array
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

            return ['lines' => $priced, 'totals' => $this->pricing->totals($priced, $regime, $rounding, $country)];
        } catch (UnsupportedCountry|\DomainException|\OverflowException) {
            return null;
        }
    }
}
