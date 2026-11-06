<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Application\IdokladFiles;
use App\Idoklad\Application\IdokladFileUnreadable;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportOutcome;
use App\Invoicing\Application\ImportedDocumentInput;
use App\Invoicing\Application\ImportedTotals;
use App\Invoicing\Application\ImportIssuedDocument;
use App\Invoicing\Application\ImportOutcome as DocumentOutcome;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\InvoicingCountry;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Countries;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * Issued invoices and proforma invoices as imported invoices and advance
 * invoices, in the order of their numbers, so each series counts on from them.
 * A document keeps what iDoklad printed: the purchaser and the firm as they
 * were on it, its regime, its rounding item as the rounding of the total, its
 * marks of being sent and exported, and its PDF. Only documents in the home
 * currency, and invoices that deduct no advance, are imported for now; credit
 * notes are reported, not imported.
 */
final readonly class DocumentsStage implements ImportStage
{
    public const string KEY = 'documents';
    public const string SOURCE = 'idoklad';
    public const string SERIES_UNKNOWN = 'idoklad.import.documents.series_unknown';
    public const string CUSTOMER_NOT_LINKED = 'idoklad.import.documents.customer_not_linked';
    public const string CURRENCY_NOT_HOME = 'idoklad.import.documents.currency_not_home';
    public const string COUNTRY_UNKNOWN = 'idoklad.import.documents.country_unknown';
    public const string PAYMENT_OPTION_UNSUPPORTED = 'idoklad.import.documents.payment_option_unsupported';
    public const string LANGUAGE_UNSUPPORTED = 'idoklad.import.documents.language_unsupported';
    public const string ZERO_RATE_UNSUPPORTED = 'idoklad.import.documents.zero_rate_unsupported';
    public const string INVALID_VALUE = 'idoklad.import.documents.invalid_value';
    public const string DEDUCTS_ADVANCES = 'idoklad.import.documents.deducts_advances';
    public const string CREDIT_NOTE_UNSUPPORTED = 'idoklad.import.documents.credit_note_unsupported';
    public const string PDF_UNREADABLE = 'idoklad.import.documents.pdf_unreadable';

    /** iDoklad's VAT rate types; Zero = 2 is a supply without VAT, which needs its VAT code. */
    private const array RATES = [0 => VatRateKind::Reduced, 1 => VatRateKind::Base, 3 => VatRateKind::SecondReduced];

    private const array PRICE_TYPES = [0 => PriceType::WithVat, 1 => PriceType::WithoutVat, 2 => PriceType::BaseOnly];

    private const array LANGUAGES = [1 => DocumentLanguage::Czech, 3 => DocumentLanguage::English];

    /** Words in the name of a payment option, as Czech and English accounts name them. */
    private const array PAYMENT_WORDS = ['převod' => PaymentMethod::BankTransfer, 'transfer' => PaymentMethod::BankTransfer, 'hotov' => PaymentMethod::Cash, 'cash' => PaymentMethod::Cash, 'kart' => PaymentMethod::Card, 'card' => PaymentMethod::Card];

    private const int ITEM_ROUND = 1;
    private const int ITEM_REDUCE = 2;
    private const int SENT_EMAIL = 8;
    private const int EXPORT_TO_ACCOUNTING = 11;

    public function __construct(
        private ImportIssuedDocument $import,
        private SeriesCatalog $catalog,
        private InvoicingCountries $countries,
        private IdokladFiles $files,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 300;
    }

    public function run(ImportContext $context): void
    {
        $country = $this->countries->for($context->firm->address()->countryCode);
        foreach ([[IdokladResource::IssuedInvoices, DocumentKind::Invoice], [IdokladResource::ProformaInvoices, DocumentKind::Advance]] as [$resource, $kind]) {
            foreach ($this->inNumberOrder($context, $resource) as $record) {
                $this->document($context, $record, $kind, $country);
            }
        }
        foreach ($context->records(IdokladResource::CreditNotes) as $record) {
            $context->report(self::KEY, $record->externalId(), ImportOutcome::Refused, reasons: [self::CREDIT_NOTE_UNSUPPORTED], values: ['resource' => IdokladResource::CreditNotes->value, 'document_number' => $this->text($record->payload()['DocumentNumber'] ?? null)]);
        }
    }

    /**
     * By issue date, then number: a typed number moves its counter only forward.
     *
     * @return list<IdokladRecord>
     */
    private function inNumberOrder(ImportContext $context, IdokladResource $resource): array
    {
        $key = fn (IdokladRecord $record): string => substr($this->text($record->payload()['DateOfIssue'] ?? null), 0, 10).' '.$this->text($record->payload()['DocumentNumber'] ?? null);
        $records = [...$context->records($resource)];
        usort($records, static fn (IdokladRecord $a, IdokladRecord $b): int => strnatcmp($key($a), $key($b)));

        return $records;
    }

    private function document(ImportContext $context, IdokladRecord $record, DocumentKind $kind, InvoicingCountry $country): void
    {
        $id = $record->externalId();
        $payload = $record->payload();
        $resource = $record->resource();
        $values = [
            'resource' => $resource->value,
            'document_number' => $this->text($payload['DocumentNumber'] ?? null),
            'sent_to_purchaser' => \is_int($payload['IsSentToPurchaser'] ?? null) ? $payload['IsSentToPurchaser'] : null,
        ];
        $linked = $context->linkedTo($resource, $id);
        if (null !== $linked) {
            $context->report(self::KEY, $id, ImportOutcome::AlreadyLinked, $linked, values: $values);

            return;
        }

        $reasons = [];
        $input = $this->input($context, $record, $kind, $country, $reasons, $values);
        if (!$input instanceof ImportedDocumentInput) {
            $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: $reasons, values: $values);

            return;
        }
        $result = $this->import->import($input);
        if (DocumentOutcome::Mismatch === $result->outcome) {
            $context->report(self::KEY, $id, ImportOutcome::Mismatch, values: $values + $this->totals('expected', $result->expected) + $this->totals('computed', $result->computed));

            return;
        }
        if (null === $result->invoiceId) {
            $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: array_values(array_unique(array_map(static fn (IssueProblem $problem): string => $problem->key, $result->problems))), values: $values);

            return;
        }
        // Imported before but no longer linked: the link is made again.
        $context->link($resource, $id, $result->invoiceId);
        $context->report(self::KEY, $id, DocumentOutcome::Imported === $result->outcome ? ImportOutcome::Created : ImportOutcome::Matched, $result->invoiceId, values: $values);
    }

    /**
     * @param list<string>                        $reasons filled with why not, when null
     * @param array<string, int|string|bool|null> $values  filled with what explains it
     */
    private function input(ImportContext $context, IdokladRecord $record, DocumentKind $kind, InvoicingCountry $country, array &$reasons, array &$values): ?ImportedDocumentInput
    {
        $payload = $record->payload();
        $items = array_values(array_filter(\is_array($payload['Items'] ?? null) ? $payload['Items'] : [], \is_array(...)));
        $deducted = \is_array($payload['ProformaInvoices'] ?? null) && [] !== $payload['ProformaInvoices'];
        if ($deducted || array_any($items, static fn (array $item): bool => self::ITEM_REDUCE === ($item['ItemType'] ?? null))) {
            $reasons[] = self::DEDUCTS_ADVANCES;

            return null;
        }
        $currency = $this->code($context, IdokladResource::Currencies, $payload['CurrencyId'] ?? null);
        $home = $country->homeCurrency();
        if ($home->value !== $currency) {
            $values['currency'] = $currency;
            $reasons[] = self::CURRENCY_NOT_HOME;

            return null;
        }

        $issueDate = $this->date($payload['DateOfIssue'] ?? null);
        $seriesId = $this->series($context, $record, $issueDate);
        $customerId = $context->linkedTo(IdokladResource::Contacts, \is_int($payload['PartnerId'] ?? null) ? $payload['PartnerId'] : 0);
        $customer = $this->party($context, $payload['PartnerAddress'] ?? null);
        $issuer = $this->party($context, $payload['MyAddress'] ?? null);
        $method = $this->paymentMethod($context, $payload['PaymentOptionId'] ?? null, $values);
        $language = \is_int($payload['ReportLanguage'] ?? null) ? self::LANGUAGES[$payload['ReportLanguage']] ?? null : null;
        $reasons = [
            ...null === $seriesId ? [self::SERIES_UNKNOWN] : [],
            ...null === $customerId ? [self::CUSTOMER_NOT_LINKED] : [],
            ...$customer instanceof DocumentParty && $issuer instanceof DocumentParty ? [] : [self::COUNTRY_UNKNOWN],
            ...$method instanceof PaymentMethod ? [] : [self::PAYMENT_OPTION_UNSUPPORTED],
            ...$language instanceof DocumentLanguage ? [] : [self::LANGUAGE_UNSUPPORTED],
        ];
        $regime = 1 === ($payload['VatRegime'] ?? null) ? VatRegime::Payer : VatRegime::NonPayer;
        $lines = [];
        $rounded = ['net' => 0, 'vat' => 0, 'gross' => 0];
        $expected = null;
        $pdf = null;
        try {
            foreach ($items as $item) {
                $prices = \is_array($item['Prices'] ?? null) ? $item['Prices'] : [];
                if (self::ITEM_ROUND === ($item['ItemType'] ?? null)) {
                    $rounded = ['net' => $rounded['net'] + $this->minor($prices['TotalWithoutVat'] ?? 0), 'vat' => $rounded['vat'] + $this->minor($prices['TotalVat'] ?? 0), 'gross' => $rounded['gross'] + $this->minor($prices['TotalWithVat'] ?? 0)];

                    continue;
                }
                $line = $this->line($item, $prices, $regime, $home);
                if ($line instanceof LineInput) {
                    $lines[] = $line;
                } else {
                    $reasons[] = self::ZERO_RATE_UNSUPPORTED;
                }
            }
            $totals = \is_array($payload['Prices'] ?? null) ? $payload['Prices'] : [];
            $payable = $this->minor($totals['TotalWithVat'] ?? null);
            $expected = new ImportedTotals($this->minor($totals['TotalWithoutVat'] ?? null) - $rounded['net'], $this->minor($totals['TotalVat'] ?? null) - $rounded['vat'], $payable - $rounded['gross'], $payable);
            $pdf = $this->pdf($context, $record);
        } catch (\InvalidArgumentException) {
            $reasons[] = self::INVALID_VALUE;
        } catch (IdokladFileUnreadable) {
            $reasons[] = self::PDF_UNREADABLE;
        }
        if (!$issueDate instanceof \DateTimeImmutable) {
            $reasons[] = self::INVALID_VALUE;
        }
        $reasons = array_values(array_unique($reasons));
        if ([] !== $reasons || null === $seriesId || null === $customerId || !$issuer instanceof DocumentParty || !$method instanceof PaymentMethod || !$language instanceof DocumentLanguage || !$expected instanceof ImportedTotals || !$issueDate instanceof \DateTimeImmutable) {
            return null;
        }

        $number = $this->text($payload['DocumentNumber'] ?? null);
        $entity = $this->entityType($record);
        $iban = str_replace(' ', '', $this->text(\is_array($payload['MyAddress'] ?? null) ? $payload['MyAddress']['Iban'] ?? null : null));
        $metadata = \is_array($payload['Metadata'] ?? null) ? $payload['Metadata'] : [];
        $document = new InvoiceInput(
            $context->firm->id(),
            $seriesId,
            $number,
            $customerId,
            $customer,
            $issueDate,
            DocumentKind::Invoice === $kind ? $this->date($payload['DateOfTaxing'] ?? null) : null,
            $this->date($payload['DateOfMaturity'] ?? null),
            $method,
            PaymentMethod::BankTransfer === $method && '' !== $iban ? $iban : null,
            $this->text($payload['VariableSymbol'] ?? null),
            $this->code($context, IdokladResource::ConstantSymbols, $payload['ConstantSymbolId'] ?? null) ?? '',
            $this->text($payload['OrderNumber'] ?? null),
            $this->text($payload['Description'] ?? null),
            $this->text($payload['ItemsTextPrefix'] ?? null),
            $this->text($payload['ItemsTextSuffix'] ?? null),
            $language,
            $lines,
            kind: $kind,
        );

        return new ImportedDocumentInput(
            $document,
            $issuer,
            $regime,
            array_any($items, static fn (array $item): bool => self::ITEM_ROUND === ($item['ItemType'] ?? null)) ? TotalRounding::WholeUnit : TotalRounding::None,
            $this->moment($metadata['DateCreated'] ?? null, $country) ?? $issueDate,
            \in_array($payload['IsSentToPurchaser'] ?? null, [1, 2], true) ? $this->logged($context, $record, $entity, self::SENT_EMAIL, $country) ?? $issueDate : null,
            // Exported = 1; Changed = 2 was changed after its export and is to be exported again.
            1 === ($payload['Exported'] ?? null) ? $this->logged($context, $record, $entity, self::EXPORT_TO_ACCOUNTING, $country) ?? $context->now() : null,
            $expected,
            self::SOURCE,
            $record->resource()->value.'/'.$record->externalId(),
            $context->runId,
            $pdf,
        );
    }

    /**
     * A line as iDoklad priced it; null for a zero-rate line of a payer, whose
     * treatment (exempt, reverse charge, outside the scope) only its VAT code
     * says, and the VAT codes are not in the snapshot.
     *
     * @param array<mixed> $item
     * @param array<mixed> $prices
     *
     * @throws \InvalidArgumentException when a value is missing or out of range
     */
    private function line(array $item, array $prices, VatRegime $regime, Currency $home): ?LineInput
    {
        $rateKind = \is_int($item['VatRateType'] ?? null) ? self::RATES[$item['VatRateType']] ?? null : null;
        if (VatRegime::Payer === $regime && !$rateKind instanceof VatRateKind) {
            return null;
        }
        $priceType = \is_int($item['PriceType'] ?? null) ? self::PRICE_TYPES[$item['PriceType']] ?? null : null;
        if (!$priceType instanceof PriceType) {
            throw new \InvalidArgumentException('Unknown price type.');
        }

        return new LineInput(
            $this->text($item['Name'] ?? null),
            Quantity::fromString($this->decimal($item['Amount'] ?? null, 4)),
            $this->text($item['Unit'] ?? null),
            Money::of($this->decimal($prices['UnitPrice'] ?? null, 4), $home),
            $priceType,
            VatRegime::Payer === $regime ? TaxTreatment::Standard : TaxTreatment::NotApplicable,
            $rateKind,
            null,
            Percent::fromString($this->decimal($item['DiscountPercentage'] ?? 0, 2)),
        );
    }

    /**
     * The series the document's numeric sequence became. The documented lists
     * of documents carry no sequence id, so without one it is the linked
     * sequence of the document's type and year whose numbers the number is.
     */
    private function series(ImportContext $context, IdokladRecord $record, ?\DateTimeImmutable $issueDate): ?string
    {
        $payload = $record->payload();
        if (\is_int($payload['NumericSequenceId'] ?? null)) {
            return $context->linkedTo(IdokladResource::NumericSequences, $payload['NumericSequenceId']);
        }
        if (!$issueDate instanceof \DateTimeImmutable) {
            return null;
        }
        $found = [];
        foreach ($context->records(IdokladResource::NumericSequences) as $sequence) {
            $fields = $sequence->payload();
            $linked = $context->linkedTo(IdokladResource::NumericSequences, $sequence->externalId());
            if (null === $linked || $this->entityType($record) !== ($fields['DocumentType'] ?? null) || (int) $issueDate->format('Y') !== ($fields['Year'] ?? null)) {
                continue;
            }
            if (null !== $this->catalog->get($linked)->format()->sequenceIn($this->text($payload['DocumentNumber'] ?? null), $issueDate)) {
                $found[$linked] = true;
            }
        }

        return 1 === \count($found) ? array_key_first($found) : null;
    }

    /**
     * The purchaser or the firm as the document printed them; null when the country is unknown.
     */
    private function party(ImportContext $context, mixed $address): ?DocumentParty
    {
        $address = \is_array($address) ? $address : [];
        $country = $context->record(IdokladResource::Countries, \is_int($address['CountryId'] ?? null) ? $address['CountryId'] : 0)?->payload() ?? [];
        $code = Countries::find($this->text($country['Name'] ?? null)) ?? Countries::find($this->text($country['NameEnglish'] ?? null));
        if (null === $code) {
            return null;
        }
        $name = $this->text($address['NickName'] ?? null);
        if ('' === $name) {
            $name = trim(implode(' ', array_filter([$this->text($address['Title'] ?? null), $this->text($address['Firstname'] ?? null), $this->text($address['Surname'] ?? null)], static fn (string $part): bool => '' !== $part)));
        }
        $email = $this->text($address['Email'] ?? null);
        $phone = $this->text($address['Phone'] ?? null);

        return new DocumentParty(
            $name,
            $this->optional($address['IdentificationNumber'] ?? null),
            $this->optional($address['VatIdentificationNumber'] ?? null),
            new Address($this->text($address['Street'] ?? null), $this->text($address['City'] ?? null), $this->text($address['PostalCode'] ?? null), $code),
            '',
            '' === $email ? null : $email,
            '' === $phone ? $this->text($address['Mobile'] ?? null) : $phone,
            $this->text($address['Www'] ?? null),
            null,
            null,
        );
    }

    /**
     * @param array<string, int|string|bool|null> $values
     */
    private function paymentMethod(ImportContext $context, mixed $optionId, array &$values): ?PaymentMethod
    {
        $name = $this->text($context->record(IdokladResource::PaymentOptions, \is_int($optionId) ? $optionId : 0)?->payload()['Name'] ?? null);
        foreach (self::PAYMENT_WORDS as $word => $method) {
            if (str_contains(mb_strtolower($name), $word)) {
                return $method;
            }
        }
        $values['payment_option'] = $name;

        return null;
    }

    /**
     * When the log last saw the action on the document; null without such an entry.
     */
    private function logged(ImportContext $context, IdokladRecord $record, int $entity, int $action, InvoicingCountry $country): ?\DateTimeImmutable
    {
        $log = $context->record(IdokladResource::Logs, $record->externalId())?->payload() ?? [];

        return $entity === ($log['EntityType'] ?? null) && $action === ($log['ActionType'] ?? null) ? $this->moment($log['DateOfAction'] ?? null, $country) : null;
    }

    /**
     * @throws IdokladFileUnreadable
     */
    private function pdf(ImportContext $context, IdokladRecord $record): ?string
    {
        $key = $record->pdfKey();
        $hash = $record->pdfSha256();
        if (!$context->writesFiles() || null === $key || null === $hash) {
            return null;
        }

        return $this->files->read($record->tenantId()->toString(), $key, $hash);
    }

    /** The type of the document in iDoklad's numeric sequences and logs. */
    private function entityType(IdokladRecord $record): int
    {
        return IdokladResource::ProformaInvoices === $record->resource() ? 1 : 0;
    }

    private function code(ImportContext $context, IdokladResource $list, mixed $id): ?string
    {
        $code = $context->record($list, \is_int($id) ? $id : 0)?->payload()['Code'] ?? null;

        return \is_string($code) ? $code : null;
    }

    /**
     * @return array<string, int|null>
     */
    private function totals(string $prefix, ?ImportedTotals $totals): array
    {
        return [$prefix.'_net' => $totals?->net, $prefix.'_vat' => $totals?->vat, $prefix.'_gross' => $totals?->gross, $prefix.'_payable' => $totals?->payable];
    }

    /**
     * @throws \InvalidArgumentException when it is no number
     */
    private function minor(mixed $amount): int
    {
        return Money::of($this->decimal($amount, 4), Currency::CZK)->minor();
    }

    /**
     * @throws \InvalidArgumentException when it is no number
     */
    private function decimal(mixed $value, int $decimals): string
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException('A number is missing.');
        }

        return \sprintf('%.'.$decimals.'F', $value);
    }

    /** A calendar date of iDoklad, "2026-10-01" or with a midnight time. */
    private function date(mixed $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($this->text($value), 0, 10));

        return false === $date ? null : $date;
    }

    /** A moment of iDoklad, written in the local time of the country without a zone. */
    private function moment(mixed $value, InvoicingCountry $country): ?\DateTimeImmutable
    {
        $text = $this->text($value);
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $text)) {
            return null;
        }

        return new \DateTimeImmutable(substr($text, 0, 19), $country->timezone());
    }

    private function optional(mixed $value): ?string
    {
        $text = $this->text($value);

        return '' === $text ? null : $text;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
