<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VariableSymbol;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\LegalEntities;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * What a new invoice starts with, for a firm and then for the customer picked. It
 * writes nothing: the number is only a preview.
 */
final readonly class NewInvoiceDefaults
{
    public function __construct(
        private InvoiceStore $store,
        private LegalEntities $firms,
        private SeriesCatalog $catalog,
        private NumberCounter $counter,
        private FirmInvoicingSettings $settings,
        private InvoicingCountries $countries,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $on the issue date, today of the country when left out
     *
     * @throws UnsupportedCountry for a firm whose country has no invoicing rules
     */
    public function for(LegalEntity $firm, ?\DateTimeImmutable $on = null): InvoiceDraftValues
    {
        $country = $this->countries->for($firm->address()->countryCode);
        $today = $on ?? \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($country->timezone())->setTime(0, 0);
        $series = $this->catalog->defaultFor($firm);
        $number = $this->counter->peek($series, $today);
        $settings = $this->settings->for($firm);
        $regime = VatRegime::of($firm->vatStatus());

        return new InvoiceDraftValues(
            $firm->id(),
            $regime,
            $country->homeCurrency(),
            $this->storedId($series, $firm),
            $number,
            $today,
            $today,
            $this->dueDate($today, InvoicingDefaults::DEFAULT_DUE_DAYS),
            PaymentMethod::BankTransfer,
            $firm->bankAccounts()[0] ?? null,
            VariableSymbol::fromNumber($number),
            $settings->constantSymbol(),
            $settings->textBefore(),
            $settings->textAfter(),
            DocumentLanguage::Czech,
            [$this->emptyLine($regime, $country->homeCurrency())],
        );
    }

    /**
     * The firm a new invoice opens in: where the user issued last, else the first active one.
     */
    public function firmFor(?string $userId): ?LegalEntity
    {
        $last = null === $userId ? null : $this->store->lastFirmOf($userId);

        return $last ?? $this->firms->active()[0] ?? null;
    }

    public function withCustomer(InvoiceDraftValues $draft, CustomerOption $customer): InvoiceDraftValues
    {
        $defaults = $customer->defaults;

        return $draft->with([
            'customerId' => $customer->id,
            'dueDate' => $this->dueDate($draft->issueDate, $defaults->dueDays),
            'paymentMethod' => $defaults->paymentMethod,
            'language' => $defaults->language,
            'lines' => VatRegime::Payer === $draft->regime ? array_map(static fn (LineInput $line): LineInput => self::treated($line, $defaults->reverseCharge), $draft->lines) : $draft->lines,
            'currencyNotice' => $defaults->currency === $draft->currency ? null : $defaults->currency,
        ]);
    }

    /**
     * A contact taxed by the customer turns every line to that; the next contact,
     * who is not, turns such lines back to the base rate. Other lines stay as typed.
     */
    private static function treated(LineInput $line, bool $reverseCharge): LineInput
    {
        if ($reverseCharge) {
            return self::line($line, TaxTreatment::ReverseCharge, null);
        }

        return TaxTreatment::ReverseCharge === $line->treatment ? self::line($line, TaxTreatment::Standard, VatRateKind::Base) : $line;
    }

    private static function line(LineInput $line, TaxTreatment $treatment, ?VatRateKind $kind): LineInput
    {
        return new LineInput($line->text, $line->quantity, $line->unit, $line->price, $line->priceType, $treatment, $kind, null, $line->discount);
    }

    private function emptyLine(VatRegime $regime, Currency $currency): LineInput
    {
        $payer = VatRegime::Payer === $regime;

        return new LineInput(
            '',
            Quantity::fromString('1'),
            '',
            Money::ofMinor(0, $currency),
            PriceType::WithoutVat,
            $payer ? TaxTreatment::Standard : TaxTreatment::NotApplicable,
            $payer ? VatRateKind::Base : null,
            null,
            Percent::fromString('0'),
        );
    }

    private function dueDate(\DateTimeImmutable $from, int $days): \DateTimeImmutable
    {
        return $from->modify(\sprintf('+%d days', $days));
    }

    private function storedId(Series $series, LegalEntity $firm): ?string
    {
        return \in_array($series, $this->catalog->everyForFirm($firm), true) ? $series->id() : null;
    }
}
