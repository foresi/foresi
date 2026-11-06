<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\InvoicingCountry;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\LegalEntities;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The choices of the selects an invoice form and a recurring template's form share,
 * as value => label, so both offer the same firms, series, rates and methods.
 */
final readonly class InvoiceFormOptions
{
    public function __construct(
        private LegalEntities $firms,
        private SeriesCatalog $catalog,
        private AmountText $amounts,
        private TranslatorInterface $translator,
        private FirmBankAccounts $accounts,
    ) {
    }

    /**
     * The active firms, and the one on the form, which may have been archived.
     *
     * @return array<string, string>
     */
    public function firms(LegalEntity $current): array
    {
        $options = [];
        foreach ($this->firms->active() as $firm) {
            $options[$firm->id()] = $firm->name();
        }
        $options[$current->id()] = $current->name();

        return $options;
    }

    /**
     * The active series of the firm of one kind, and $own, which may be archived and
     * decides the kind. A firm that has none of the kind yet offers its default,
     * which the first save stores.
     *
     * @return array<string, string>
     */
    public function series(LegalEntity $firm, ?Series $own = null, DocumentKind $kind = DocumentKind::Invoice): array
    {
        $kind = $own?->kind() ?? $kind;
        $options = [];
        foreach ($this->catalog->forFirm($firm, $kind) as $series) {
            $options[$series->id()] = $this->seriesName($series);
        }
        if ($own instanceof Series) {
            $options[$own->id()] = $this->seriesName($own);
        }

        return [] === $options ? ['' => $this->translator->trans('series.name.'.$kind->standardSeriesName())] : $options;
    }

    /**
     * The series a recurring template may issue in: those of invoices and of advance
     * invoices, and $own, which may be archived.
     *
     * @return array<string, string>
     */
    public function recurringSeries(LegalEntity $firm, ?Series $own = null): array
    {
        $options = $this->series($firm, DocumentKind::Invoice === $own?->kind() ? $own : null);
        foreach ($this->catalog->forFirm($firm, DocumentKind::Advance) as $series) {
            $options[$series->id()] = $this->seriesName($series);
        }
        if ($own instanceof Series && DocumentKind::Advance === $own->kind()) {
            $options[$own->id()] = $this->seriesName($own);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function vat(InvoiceFormContext $context, \DateTimeImmutable $taxPoint): array
    {
        $options = [
            InvoiceFormReader::VAT_BASE => $this->rateLabel($context, VatRateKind::Base, $taxPoint),
            InvoiceFormReader::VAT_REDUCED => $this->rateLabel($context, VatRateKind::Reduced, $taxPoint),
        ];
        if ($this->hasRate($context, VatRateKind::SecondReduced, $taxPoint)) {
            $options[InvoiceFormReader::VAT_SECOND_REDUCED] = $this->rateLabel($context, VatRateKind::SecondReduced, $taxPoint);
        }
        $options[InvoiceFormReader::VAT_REVERSE_CHARGE] = $this->translator->trans('invoice.vat.reverse_charge');
        $options[InvoiceFormReader::VAT_EXEMPT] = $this->translator->trans('invoice.vat.exempt');

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function priceTypes(): array
    {
        $options = [];
        foreach (PriceType::cases() as $type) {
            $options[$type->value] = $this->translator->trans('invoice.price_type.'.$type->value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function supplyCodes(InvoiceFormContext $context, \DateTimeImmutable $taxPoint): array
    {
        $options = ['' => $this->translator->trans('invoice.line.supplyCode_choose')];
        foreach ($context->country->supplyCodes($taxPoint) as $code) {
            $options[$code->code] = \sprintf('%s – %s', $code->code, $this->translator->trans($code->labelKey));
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function paymentMethods(): array
    {
        $options = [];
        foreach (PaymentMethod::cases() as $method) {
            $options[$method->value] = $this->translator->trans('invoice.payment.'.$method->value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function languages(): array
    {
        $options = [];
        foreach (DocumentLanguage::cases() as $language) {
            $options[$language->value] = $this->translator->trans('invoice.language.'.$language->value);
        }

        return $options;
    }

    /**
     * The active accounts of the firm, primary first, and $own, which the firm may have archived since.
     *
     * @return array<string, string>
     */
    public function bankAccounts(LegalEntity $firm, ?string $own = null): array
    {
        $accounts = $this->accounts->ibansOf($firm);
        if (null !== $own && !\in_array($own, $accounts, true)) {
            $accounts[] = $own;
        }

        return array_combine($accounts, $accounts);
    }

    /**
     * What the form shows of each account of bankAccounts(), by IBAN: its label,
     * the number as people of the country write it (null abroad) and the IBAN in
     * groups of four, as the invoice prints them. The name is what the select offers.
     *
     * @param list<string> $ibans
     *
     * @return array<string, array{label: string, number: ?string, iban: string, name: string}>
     */
    public function bankDetails(LegalEntity $firm, InvoicingCountry $country, array $ibans): array
    {
        $details = [];
        foreach ($ibans as $iban) {
            $label = $this->accounts->ofIban($firm, $iban)?->label() ?? '';
            $number = $country->domesticAccount($iban);
            $grouped = trim(chunk_split($iban, 4, ' '));
            $details[$iban] = ['label' => $label, 'number' => $number, 'iban' => $grouped, 'name' => '' !== $label ? $label : ($number ?? $grouped)];
        }

        return $details;
    }

    private function seriesName(Series $series): string
    {
        return DocumentKind::isStandardSeriesName($series->name()) ? $this->translator->trans('series.name.'.$series->name()) : $series->name();
    }

    /**
     * Whether the kind has a rate on the tax point; a kind a country had only
     * once, such as a second reduced rate, is offered only where it applies.
     */
    private function hasRate(InvoiceFormContext $context, VatRateKind $kind, \DateTimeImmutable $taxPoint): bool
    {
        try {
            $context->country->rate($kind, $taxPoint);

            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    private function rateLabel(InvoiceFormContext $context, VatRateKind $kind, \DateTimeImmutable $taxPoint): string
    {
        try {
            return $this->translator->trans('invoice.rate', ['rate' => $this->amounts->trimmed($context->country->rate($kind, $taxPoint)->decimal())]);
        } catch (\DomainException) {
            return $this->translator->trans('invoice.vat.'.$kind->value);
        }
    }
}
