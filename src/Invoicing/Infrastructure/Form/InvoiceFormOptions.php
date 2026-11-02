<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\VatRateKind;
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
     * The active series of the firm, and $own, which may be archived. A firm that has
     * none yet offers its default, which the first save stores.
     *
     * @return array<string, string>
     */
    public function series(LegalEntity $firm, ?Series $own = null): array
    {
        $options = [];
        foreach ($this->catalog->forFirm($firm) as $series) {
            $options[$series->id()] = $this->seriesName($series);
        }
        if ($own instanceof Series) {
            $options[$own->id()] = $this->seriesName($own);
        }

        return [] === $options ? ['' => $this->translator->trans('series.name.standard')] : $options;
    }

    /**
     * @return array<string, string>
     */
    public function vat(InvoiceFormContext $context, \DateTimeImmutable $taxPoint): array
    {
        return [
            InvoiceFormReader::VAT_BASE => $this->rateLabel($context, VatRateKind::Base, $taxPoint),
            InvoiceFormReader::VAT_REDUCED => $this->rateLabel($context, VatRateKind::Reduced, $taxPoint),
            InvoiceFormReader::VAT_REVERSE_CHARGE => $this->translator->trans('invoice.vat.reverse_charge'),
            InvoiceFormReader::VAT_EXEMPT => $this->translator->trans('invoice.vat.exempt'),
        ];
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
     * The accounts of the firm, and $own, which the firm may have dropped.
     *
     * @return array<string, string>
     */
    public function bankAccounts(LegalEntity $firm, ?string $own = null): array
    {
        $accounts = $firm->bankAccounts();
        if (null !== $own && !\in_array($own, $accounts, true)) {
            $accounts[] = $own;
        }

        return array_combine($accounts, $accounts);
    }

    private function seriesName(Series $series): string
    {
        return Series::STANDARD === $series->name() ? $this->translator->trans('series.name.standard') : $series->name();
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
