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
use App\Shared\Domain\Money;
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
     * @return array{lines: list<array{total: string, discount: string}>, summaries: list<array{label: string, base: string, vat: string}>, net: string, vat: ?string, rounding: ?string, payable: string}
     *
     * @throws \OverflowException when an amount does not fit
     * @throws \DomainException   when the country has no rate on that date
     */
    public function of(array $lines, InvoiceFormContext $context, \DateTimeImmutable $taxPoint): array
    {
        $priced = $this->pricing->priced($lines, $context->regime, $context->country, $taxPoint);
        $totals = $this->pricing->totals($priced, $context->regime, $context->rounding, $context->country);

        return [
            'lines' => $this->lines($priced, $lines, $context, $taxPoint),
            'summaries' => $context->isPayer() ? array_map($this->summary(...), $totals->summaries) : [],
            'net' => $this->amounts->shown($totals->net),
            'vat' => $context->isPayer() ? $this->amounts->shown($totals->vat) : null,
            'rounding' => $totals->rounding->isZero() ? null : $this->amounts->shown($totals->rounding),
            'payable' => $this->amounts->shown($totals->payable),
        ];
    }

    /**
     * A line shows what it comes to before the discount and the discount as a deduction
     * of its own; the two add up to the amount of the line the document stores.
     *
     * @param list<PricedLine> $priced
     * @param list<LineInput>  $lines
     *
     * @return list<array{total: string, discount: string}>
     */
    private function lines(array $priced, array $lines, InvoiceFormContext $context, \DateTimeImmutable $taxPoint): array
    {
        $full = $this->pricing->priced(array_map($this->withoutDiscount(...), $lines), $context->regime, $context->country, $taxPoint);
        $rows = [];
        foreach ($priced as $index => $line) {
            $before = $full[$index]->amounts->gross;
            $deduction = $line->amounts->gross->minor() - $before->minor();
            $rows[] = ['total' => $this->amounts->shown($before), 'discount' => 0 === $deduction ? '' : $this->amounts->shown(Money::ofMinor($deduction, $before->currency()))];
        }

        return $rows;
    }

    private function withoutDiscount(LineInput $line): LineInput
    {
        return new LineInput($line->text, $line->quantity, $line->unit, $line->price, $line->priceType, $line->treatment, $line->rateKind, $line->supplyCode, Percent::fromString('0'));
    }

    /**
     * @return array{label: string, base: string, vat: string}
     */
    private function summary(RateSummary $summary): array
    {
        $vat = $summary->rate instanceof Percent ? $this->amounts->shown($summary->vat) : '';

        return ['label' => $this->label($summary), 'base' => $this->amounts->shown($summary->base), 'vat' => $vat];
    }

    private function label(RateSummary $summary): string
    {
        return $summary->rate instanceof Percent
            ? $this->translator->trans('invoice.rate', ['rate' => $this->amounts->trimmed($summary->rate->decimal())])
            : $this->translator->trans('invoice.vat.'.$summary->treatment->value);
    }
}
