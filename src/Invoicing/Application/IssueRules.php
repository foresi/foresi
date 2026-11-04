<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\NumberKey;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\PartyQuery;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
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
        private FirmInvoicingSettings $settings,
        private PrintableText $printable,
        private InvoiceSends $sends,
        private FirmBankAccounts $accounts,
        private LineRules $shared,
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
        $problems = [...$problems, ...$this->printing($input, $firm, $existing, $copy)];
        $regime = $existing?->vatRegime() ?? VatRegime::of($firm->vatStatus());
        $problems = [...$problems, ...$this->shared->lines($input->lines, $regime, $country, $copy?->vatId, $existing?->issuer()->address->countryCode ?? $firm->address()->countryCode, $input->taxPointDate)];
        if ([] !== $problems || !$input->taxPointDate instanceof \DateTimeImmutable) {
            return $problems;
        }

        return $this->shared->amounts($input->lines, $regime, $existing?->rounding() ?? $this->settings->for($firm)->rounding(), $country, $input->taxPointDate);
    }

    /**
     * @return list<IssueProblem>
     */
    private function firm(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing): array
    {
        if ($existing instanceof Invoice) {
            return $input->firmId === $firm->id() ? [] : [new IssueProblem('firm', 'invoice.problem.firm_locked')];
        }

        return $this->shared->firm($firm);
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
     * One of the firm's active accounts, or the one the document already has: the
     * firm may have archived it since.
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

        return $iban === $existing?->bankAccount() || \in_array($iban, $this->accounts->ibansOf($firm), true);
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
        // A send that went out fixes them too, delivered or not: one that went out
        // while the invoice went to the trash leaves it undelivered after a restore.
        if ($existing instanceof Invoice && $this->sends->hasGoneOut($existing)) {
            return [new IssueProblem('number', 'invoice.problem.number_locked_sent')];
        }
        // A queued send fixes them too, or the customer could get a number the invoice no longer has.
        if ($existing instanceof Invoice && $this->sends->hasQueued($existing)) {
            return [new IssueProblem('number', 'invoice.problem.number_locked_sending')];
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
        if ('' === $key || mb_strlen($number) > Invoice::MAX_NUMBER_LENGTH || \strlen($key) > Invoice::MAX_NUMBER_LENGTH) {
            return [new IssueProblem('number', 'invoice.problem.number_invalid')];
        }
        $country = $this->country($firm, $existing);
        if ($country instanceof InvoicingCountry && !$country->acceptsNumber($number)) {
            return [new IssueProblem('number', 'invoice.problem.number_not_accepted')];
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
        if ($party->id() !== $existing?->customerParty()->id() && $this->contacts->isArchivedFor($party, $firm)) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.customer_archived');
        }
        $copy = $this->copies->for($input, $party, $firm, $existing);
        if (!$copy instanceof DocumentParty || !$this->isComplete($copy)) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.customer_address_incomplete');
        } elseif (!$this->fits($copy)) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.too_long');
        } elseif ($input->customerOnDocument instanceof DocumentParty && null !== $copy->vatId && !LineRules::vatIdOf($copy->vatId) instanceof VatId) {
            $problems[] = new IssueProblem('customer', 'invoice.problem.customer_vat_id_invalid');
        }

        return $problems;
    }

    /**
     * Everything of the document that prints and a person typed. The firm counts only
     * on a new document: an issued one holds its copy for good.
     *
     * @return list<IssueProblem>
     */
    private function printing(InvoiceInput $input, LegalEntity $firm, ?Invoice $existing, ?DocumentParty $customer): array
    {
        $texts = [
            'number' => $input->typedNumber ?? '',
            'orderNumber' => $input->orderNumber,
            'description' => $input->description,
            'textBefore' => $input->textBefore,
            'textAfter' => $input->textAfter,
        ];
        $problems = $this->shared->unprintable([...$texts, ...$this->shared->lineTexts($input->lines)]);
        if ($customer instanceof DocumentParty) {
            $problems = [...$problems, ...$this->printingOf('customer', $customer)];
        }
        if (!$existing instanceof Invoice) {
            $problems = [...$problems, ...$this->printingOf('firm', DocumentParty::ofIssuer($firm, $this->settings->for($firm)))];
        }

        return $problems;
    }

    /**
     * @return list<IssueProblem>
     */
    private function printingOf(string $field, DocumentParty $party): array
    {
        $parts = [
            'name' => $party->name,
            'street' => $party->address->street,
            'city' => $party->address->city,
            'postal_code' => $party->address->postalCode,
            'registration_number' => $party->registrationNumber ?? '',
            'vat_id' => $party->vatId ?? '',
            'register_entry' => $party->registerEntry,
            'email' => $party->email ?? '',
            'phone' => $party->phone,
            'web' => $party->web,
        ];
        $problems = [];
        foreach ($parts as $part => $text) {
            $characters = $this->printable->unprintable($text);
            if ([] !== $characters) {
                $problems[] = new IssueProblem($field, 'invoice.problem.unprintable_part', ['part' => $part, 'characters' => implode(', ', $characters)]);
            }
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
}
