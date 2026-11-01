<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\NumberKey;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\PartyQuery;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\InvalidVatId;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatId;

/**
 * What a document must be to be saved, on every path. It reads and never writes,
 * so it runs before any transaction. On an edit the document answers for what was
 * frozen at issue: its regime and issuer stay, so what became of the firm or the
 * contact since then does not stop an old document from being edited.
 */
final readonly class IssueRules
{
    private const string VARIABLE_SYMBOL = '/^\d{1,10}$/D';

    public function __construct(
        private InvoicingCountries $countries,
        private PartyQuery $parties,
        private ContactSettingsForFirm $contacts,
        private SeriesCatalog $catalog,
        private InvoiceStore $store,
        private CustomerCopies $copies,
        private LinePricing $pricing,
        private FirmInvoicingSettings $settings,
    ) {
    }

    /**
     * @param Invoice|null $existing the document being edited
     *
     * @return list<IssueProblem> empty when the document may be saved
     */
    public function check(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing = null): array
    {
        $copy = null;
        $problems = [
            ...$this->firm($input, $firm, $existing),
            ...$this->document($input, $firm, $existing),
            ...$this->number($input, $firm, $existing),
            ...$this->customer($input, $firm, $existing, $copy),
        ];
        $country = $this->country($firm, $existing);
        if (!$country instanceof InvoicingCountry) {
            return [...$problems, new IssueProblem('firm', 'invoice.problem.firm_country_unsupported')];
        }
        $regime = $existing?->vatRegime() ?? VatRegime::of($firm->vatStatus());
        $problems = [...$problems, ...$this->lines($input, $regime, $country, $copy, $existing?->issuer()->address->countryCode ?? $firm->address()->countryCode)];

        return [] === $problems ? $this->amounts($input, $regime, $existing?->rounding() ?? $this->settings->for($firm)->rounding(), $country) : $problems;
    }

    /**
     * @return list<IssueProblem>
     */
    private function firm(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing): array
    {
        if ($existing instanceof Invoice) {
            return $input->firmId === $firm->id() ? [] : [new IssueProblem('firm', 'invoice.problem.firm_locked')];
        }
        $problems = [];
        if ($firm->isArchived()) {
            $problems[] = new IssueProblem('firm', 'invoice.problem.firm_archived');
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
     * @return list<IssueProblem>
     */
    private function document(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing): array
    {
        $problems = [];
        if ($existing?->isTrashed() ?? false) {
            $problems[] = new IssueProblem('document', 'invoice.problem.trashed');
        }
        if (!$input->taxPointDate instanceof \DateTimeImmutable) {
            $problems[] = new IssueProblem('taxPointDate', 'invoice.problem.tax_point_missing');
        }
        if (!$input->dueDate instanceof \DateTimeImmutable) {
            $problems[] = new IssueProblem('dueDate', 'invoice.problem.due_date_missing');
        }
        if ('' !== $input->variableSymbol && 1 !== preg_match(self::VARIABLE_SYMBOL, $input->variableSymbol)) {
            $problems[] = new IssueProblem('variableSymbol', 'invoice.problem.variable_symbol_invalid');
        }
        if (mb_strlen($input->orderNumber) > Invoice::MAX_ORDER_NUMBER_LENGTH) {
            $problems[] = new IssueProblem('orderNumber', 'invoice.problem.too_long');
        }
        if (mb_strlen($input->constantSymbol) > Invoice::MAX_CONSTANT_SYMBOL_LENGTH) {
            $problems[] = new IssueProblem('constantSymbol', 'invoice.problem.too_long');
        } elseif ('' !== $input->constantSymbol && !ctype_digit($input->constantSymbol)) {
            $problems[] = new IssueProblem('constantSymbol', 'invoice.problem.constant_symbol_invalid');
        }
        if (PaymentMethod::BankTransfer === $input->paymentMethod && !$this->accountAllowed($input->bankAccount, $firm, $existing)) {
            $problems[] = new IssueProblem('bankAccount', 'invoice.problem.bank_account_foreign');
        }

        return $problems;
    }

    /**
     * One of the firm's accounts, or the one the document already has: the firm may
     * have dropped it since.
     */
    private function accountAllowed(?string $account, LegalEntity $firm, ?Invoice $existing): bool
    {
        if (null === $account) {
            return false;
        }
        try {
            $iban = Iban::fromString($account)->toString();
        } catch (InvalidIban) {
            return false;
        }

        return \in_array($iban, $firm->bankAccounts(), true) || $iban === $existing?->bankAccount();
    }

    /**
     * @return list<IssueProblem>
     */
    private function number(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing): array
    {
        if ($existing instanceof Invoice && !$existing->isRenumberedBy($input->seriesId, $input->typedNumber)) {
            return [];
        }
        if ($existing?->isDelivered() ?? false) {
            return [new IssueProblem('number', 'invoice.problem.number_locked')];
        }
        $series = $this->series($input, $firm, $existing);
        if (!$series instanceof Series) {
            return [new IssueProblem('series', 'invoice.problem.series_unknown')];
        }
        if ($series->firm()->id() !== $firm->id()) {
            return [new IssueProblem('series', 'invoice.problem.series_of_another_firm')];
        }
        if ($series->isArchived() && $series->id() !== $existing?->series()->id()) {
            return [new IssueProblem('series', 'invoice.problem.series_archived')];
        }

        return null === $input->typedNumber ? [] : $this->typedNumber(trim($input->typedNumber), $series, $input->issueDate, $firm, $existing);
    }

    private function series(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing): ?Series
    {
        if (null === $input->seriesId) {
            return $existing?->series() ?? $this->catalog->defaultFor($firm);
        }
        try {
            return $this->catalog->get($input->seriesId);
        } catch (UnknownSeries) {
            return null;
        }
    }

    /**
     * @return list<IssueProblem>
     */
    private function typedNumber(string $number, Series $series, \DateTimeImmutable $issueDate, LegalEntity $firm, ?Invoice $existing): array
    {
        $key = NumberKey::of($number);
        if ('' === $key || mb_strlen($number) > Invoice::MAX_NUMBER_LENGTH) {
            return [new IssueProblem('number', 'invoice.problem.number_invalid')];
        }
        if ($series->format()->exceedsCounter($number, $issueDate)) {
            return [new IssueProblem('number', 'invoice.problem.number_out_of_range')];
        }
        if ($this->store->numberTaken($firm, $key, $existing)) {
            return [new IssueProblem('number', 'invoice.problem.number_taken')];
        }

        return [];
    }

    /**
     * @return list<IssueProblem>
     */
    private function customer(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing, ?DocumentParty &$copy): array
    {
        $party = $this->parties->find($input->customerId);
        if (!$party instanceof Party) {
            return [new IssueProblem('customer', 'invoice.problem.customer_unknown')];
        }
        $problems = [];
        $isNew = $party->id() !== $existing?->customerParty()->id();
        if ($isNew && $this->contacts->isArchivedFor($party, $firm)) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.customer_archived');
        }
        if ($isNew && !$this->contacts->hasCustomerRole($party, $firm)) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.customer_role');
        }
        $copy = $this->copies->for($input, $party, $firm, $existing);
        if (!$copy instanceof DocumentParty || !$this->isComplete($copy)) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.customer_address_incomplete');
        } elseif (!$this->fits($copy)) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.too_long');
        } elseif ($input->customerOnDocument instanceof DocumentParty && null !== $copy->vatId && !$this->vatIdOf($copy) instanceof VatId) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.customer_vat_id_invalid');
        }

        return $problems;
    }

    private function isComplete(DocumentParty $copy): bool
    {
        $address = $copy->address;

        return '' !== trim($copy->name) && '' !== trim($address->street) && '' !== trim($address->city) && '' !== trim($address->postalCode) && '' !== trim($address->countryCode);
    }

    private function fits(DocumentParty $copy): bool
    {
        $limits = [
            [$copy->name, DocumentParty::MAX_NAME],
            [$copy->registrationNumber, DocumentParty::MAX_REGISTRATION_NUMBER],
            [$copy->vatId, DocumentParty::MAX_VAT_ID],
            [$copy->registerEntry, DocumentParty::MAX_REGISTER_ENTRY],
            [$copy->email, DocumentParty::MAX_EMAIL],
            [$copy->phone, DocumentParty::MAX_PHONE],
            [$copy->web, DocumentParty::MAX_WEB],
            [$copy->address->street, DocumentParty::MAX_STREET],
            [$copy->address->city, DocumentParty::MAX_CITY],
            [$copy->address->postalCode, DocumentParty::MAX_POSTAL_CODE],
            [$copy->address->countryCode, 2],
            [$copy->logo, DocumentParty::MAX_IMAGE_NAME],
            [$copy->stamp, DocumentParty::MAX_IMAGE_NAME],
        ];
        foreach ($limits as [$value, $limit]) {
            if (mb_strlen($value ?? '') > $limit) {
                return false;
            }
        }

        return true;
    }

    private function country(LegalEntity $firm, ?Invoice $existing): ?InvoicingCountry
    {
        try {
            return $this->countries->for($existing?->issuer()->address->countryCode ?? $firm->address()->countryCode);
        } catch (UnsupportedCountry) {
            return null;
        }
    }

    /**
     * @return list<IssueProblem>
     */
    private function lines(InvoiceInput $input, VatRegime $regime, InvoicingCountry $country, ?DocumentParty $customer, string $issuerCountry): array
    {
        if ([] === $input->lines) {
            return [new IssueProblem('lines', 'invoice.problem.no_lines')];
        }
        $problems = [];
        foreach ($input->lines as $index => $line) {
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
                $problems = [...$problems, ...$this->reverseCharge($line, $field, $regime, $country, $customer, $issuerCountry, $input->taxPointDate)];
            } elseif (VatRegime::Payer === $regime && TaxTreatment::NotApplicable === $line->treatment) {
                $problems[] = new IssueProblem($field.'.vat', 'invoice.problem.vat_invalid');
            } elseif (VatRegime::Payer === $regime && $line->treatment->hasRate() && !$this->hasRate($line, $country, $input->taxPointDate)) {
                $problems[] = new IssueProblem($field.'.rate', 'invoice.problem.line_rate_missing');
            }
        }

        return $problems;
    }

    /**
     * A missing tax point is a problem of its own, so the line is not blamed for it.
     */
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
    private function reverseCharge(LineInput $line, string $field, VatRegime $regime, InvoicingCountry $country, ?DocumentParty $customer, string $issuerCountry, ?\DateTimeImmutable $taxPoint): array
    {
        if (VatRegime::Payer !== $regime) {
            return [new IssueProblem($field.'.treatment', 'invoice.problem.reverse_charge_payer')];
        }
        $vatId = $this->vatIdOf($customer);
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

    private function vatIdOf(?DocumentParty $customer): ?VatId
    {
        if (null === $customer?->vatId) {
            return null;
        }
        try {
            return VatId::fromString($customer->vatId);
        } catch (InvalidVatId) {
            return null;
        }
    }

    /**
     * @return list<IssueProblem>
     */
    private function amounts(InvoiceInput $input, VatRegime $regime, TotalRounding $rounding, InvoicingCountry $country): array
    {
        if (!$input->taxPointDate instanceof \DateTimeImmutable) {
            return [];
        }
        try {
            $lines = $this->pricing->priced($input->lines, $regime, $country, $input->taxPointDate);
            $totals = $this->pricing->totals($lines, $regime, $rounding, $country->homeCurrency());
        } catch (\OverflowException) {
            return [new IssueProblem('lines', 'invoice.problem.amount_out_of_range')];
        }

        return $totals->payable->isNegative() ? [new IssueProblem('lines', 'invoice.problem.payable_negative')] : [];
    }
}
