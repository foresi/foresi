<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\LinePricing;
use App\Invoicing\Application\PricedLine;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\RateSummary;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The live figures of a form as text, from the calculator a save uses, so the
 * browser never counts and the numbers cannot differ from the saved document.
 */
final readonly class InvoiceFigures
{
    public function __construct(private LinePricing $pricing, private AmountText $amounts, private TranslatorInterface $translator)
    {
    }

    /**
     * @param list<LineInput> $lines
     *
     * @return array{lines: list<array{total: string}>, summaries: list<array{label: string, base: string, vat: string}>, net: string, vat: ?string, rounding: ?string, payable: string}
     *
     * @throws \OverflowException when an amount does not fit
     * @throws \DomainException   when the country has no rate on that date
     */
    public function of(array $lines, InvoiceFormContext $context, \DateTimeImmutable $taxPoint): array
    {
        $priced = $this->pricing->priced($lines, $context->regime, $context->country, $taxPoint);
        $totals = $this->pricing->totals($priced, $context->regime, $context->rounding, $context->currency);

        return [
            'lines' => array_map(fn (PricedLine $line): array => ['total' => $this->amounts->money($line->amounts->gross)], $priced),
            'summaries' => $context->isPayer() ? array_map($this->summary(...), $totals->summaries) : [],
            'net' => $this->amounts->money($totals->net),
            'vat' => $context->isPayer() ? $this->amounts->money($totals->vat) : null,
            'rounding' => $totals->rounding->isZero() ? null : $this->amounts->money($totals->rounding),
            'payable' => $this->amounts->money($totals->payable),
        ];
    }

    /**
     * @return array{label: string, base: string, vat: string}
     */
    private function summary(RateSummary $summary): array
    {
        $vat = $summary->rate instanceof Percent ? $this->amounts->money($summary->vat) : '';

        return ['label' => $this->label($summary), 'base' => $this->amounts->money($summary->base), 'vat' => $vat];
    }

    private function label(RateSummary $summary): string
    {
        return $summary->rate instanceof Percent
            ? $this->translator->trans('invoice.rate', ['rate' => $this->amounts->trimmed($summary->rate->decimal())])
            : $this->translator->trans('invoice.vat.'.$summary->treatment->value);
    }
}
