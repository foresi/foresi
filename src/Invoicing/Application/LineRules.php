<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\InvalidVatId;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Money;

/**
 * The checks an invoice and a recurring template share: whether the firm may
 * issue, whether the typed lines make a document and whether a text prints. They
 * read and never write. IssueRules and RecurringRules both ask here, so a template
 * is held to the rules its invoices will meet.
 */
final readonly class LineRules
{
    public function __construct(private LinePricing $pricing, private PrintableText $printable)
    {
    }

    /**
     * What keeps a firm from issuing a new document.
     *
     * @return list<IssueProblem>
     */
    public function firm(LegalEntity $firm): array
    {
        $problems = [];
        if ($firm->isArchived()) {
            $problems[] = new IssueProblem('firm', 'invoice.problem.firm_archived');
        }
        if (VatStatus::IdentifiedPerson === $firm->vatStatus()) {
            $problems[] = new IssueProblem('firm', 'invoice.problem.firm_identified_person');
        }
        if ($firm->vatStatus()->requiresVatId() && !$firm->vatId() instanceof VatId) {
            $problems[] = new IssueProblem('firm', 'invoice.problem.firm_vat_id_missing');
        }
        if ('' === trim($firm->registerEntry())) {
            $problems[] = new IssueProblem('firm', 'invoice.problem.firm_register_entry_missing');
        }

        return $problems;
    }

    /**
     * @param array<string, string> $texts what a person typed, by field
     *
     * @return list<IssueProblem>
     */
    public function unprintable(array $texts): array
    {
        $problems = [];
        foreach ($texts as $field => $text) {
            $characters = $this->printable->unprintable($text);
            if ([] !== $characters) {
                $problems[] = new IssueProblem($field, 'invoice.problem.unprintable', ['characters' => implode(', ', $characters)]);
            }
        }

        return $problems;
    }

    /**
     * The texts and units of lines, by the field a form shows them in.
     *
     * @param list<LineInput> $lines
     *
     * @return array<string, string>
     */
    public function lineTexts(array $lines): array
    {
        $texts = [];
        foreach ($lines as $index => $line) {
            $texts['lines.'.$index.'.text'] = $line->text;
            $texts['lines.'.$index.'.unit'] = $line->unit;
        }

        return $texts;
    }

    /**
     * A missing tax point blames no line: it is a problem of its own.
     *
     * @param list<LineInput> $lines
     * @param string|null     $customerVatId the VAT id the customer is invoiced under
     *
     * @return list<IssueProblem>
     */
    public function lines(array $lines, VatRegime $regime, InvoicingCountry $country, ?string $customerVatId, string $issuerCountry, ?\DateTimeImmutable $taxPoint): array
    {
        if ([] === $lines) {
            return [new IssueProblem('lines', 'invoice.problem.no_lines')];
        }
        if (\count($lines) > Invoice::MAX_LINES) {
            return [new IssueProblem('lines', 'invoice.problem.too_many_lines')];
        }
        $problems = [];
        foreach ($lines as $index => $line) {
            $field = 'lines.'.$index;
            if ('' === trim($line->text)) {
                $problems[] = new IssueProblem($field.'.text', 'invoice.problem.line_text_missing');
            }
            if (mb_strlen($line->unit) > InvoiceLine::MAX_UNIT_LENGTH) {
                $problems[] = new IssueProblem($field.'.unit', 'invoice.problem.too_long');
            }
            if ($line->price->currency() !== $country->homeCurrency()) {
                $problems[] = new IssueProblem($field.'.price', 'invoice.problem.currency_not_home');
            }
            if (TaxTreatment::ReverseCharge === $line->treatment) {
                $problems = [...$problems, ...$this->reverseCharge($line, $field, $regime, $country, $customerVatId, $issuerCountry, $taxPoint)];
            } elseif (VatRegime::Payer === $regime && TaxTreatment::NotApplicable === $line->treatment) {
                $problems[] = new IssueProblem($field.'.vat', 'invoice.problem.vat_invalid');
            } elseif (VatRegime::Payer === $regime && $line->treatment->hasRate() && !$this->hasRate($line, $country, $taxPoint)) {
                $problems[] = new IssueProblem($field.'.rate', 'invoice.problem.line_rate_missing');
            }
        }

        return $problems;
    }

    /**
     * Whether the figures of the lines fit a document, once the lines passed.
     *
     * @param list<LineInput> $lines
     *
     * @return list<IssueProblem>
     */
    public function amounts(array $lines, VatRegime $regime, TotalRounding $rounding, InvoicingCountry $country, \DateTimeImmutable $taxPoint): array
    {
        try {
            $priced = $this->pricing->priced($lines, $regime, $country, $taxPoint);
            $totals = $this->pricing->totals($priced, $regime, $rounding, $country);
        } catch (\OverflowException) {
            return [new IssueProblem('lines', 'invoice.problem.amount_out_of_range')];
        }
        $amounts = [$totals->net, $totals->gross, $totals->payable];
        foreach ($priced as $line) {
            $amounts[] = $line->amounts->unitNet;
            $amounts[] = $line->amounts->net;
            $amounts[] = $line->amounts->gross;
        }
        if (array_any($amounts, static fn (Money $amount): bool => abs($amount->minor()) > Invoice::MAX_AMOUNT_MINOR)) {
            return [new IssueProblem('lines', 'invoice.problem.amount_out_of_range')];
        }

        // Advances deducted beyond the supply leave a difference to refund (§37a(2)(b)).
        return $totals->payable->isNegative() && [] === AdvanceDeductions::deducted($lines) ? [new IssueProblem('lines', 'invoice.problem.payable_negative')] : [];
    }

    public static function vatIdOf(?string $vatId): ?VatId
    {
        if (null === $vatId) {
            return null;
        }
        try {
            return VatId::fromString($vatId);
        } catch (InvalidVatId) {
            return null;
        }
    }

    private function hasRate(LineInput $line, InvoicingCountry $country, ?\DateTimeImmutable $taxPoint): bool
    {
        if (!$line->rateKind instanceof VatRateKind) {
            return false;
        }
        if (!$taxPoint instanceof \DateTimeImmutable) {
            return true;
        }
        try {
            $country->rate($line->rateKind, $taxPoint);

            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    /**
     * @return list<IssueProblem>
     */
    private function reverseCharge(LineInput $line, string $field, VatRegime $regime, InvoicingCountry $country, ?string $customerVatId, string $issuerCountry, ?\DateTimeImmutable $taxPoint): array
    {
        if (VatRegime::Payer !== $regime) {
            return [new IssueProblem($field.'.treatment', 'invoice.problem.reverse_charge_payer')];
        }
        $vatId = self::vatIdOf($customerVatId);
        if (!$vatId instanceof VatId) {
            return [new IssueProblem('customer', 'invoice.problem.reverse_charge_customer_vat_id')];
        }
        $code = $line->supplyCode ?? '';
        if ($vatId->country() !== $issuerCountry) {
            return '' === $code ? [] : [new IssueProblem($field.'.supplyCode', 'invoice.problem.supply_code_not_allowed')];
        }
        if (!$taxPoint instanceof \DateTimeImmutable) {
            return [];
        }
        foreach ($country->supplyCodes($taxPoint) as $known) {
            if ($known->code === $code) {
                return [];
            }
        }

        return [new IssueProblem($field.'.supplyCode', 'invoice.problem.supply_code_invalid')];
    }
}
