<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportOutcome;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PreviewRow;
use App\Invoicing\Application\RecurringHistory;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\RecurringPreview;
use App\Invoicing\Application\RecurringRefused;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\InvalidRecurrence;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\FirmBankAccounts;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * iDoklad recurring invoices as Foresi templates, stored paused so a template
 * never runs in both systems: the person resumes each one after its iDoklad
 * twin's last run. The schedule, the invoice data and the delivery are mapped
 * field by field; the customer and the series are the ones the earlier stages
 * linked. What iDoklad issued carries over, and the next date Foresi works out
 * must be iDoklad's next issue, or the template is refused with both dates, not
 * guessed. Templates of advance invoices, in a foreign currency, with a
 * variable Foresi does not know or with anything else Foresi cannot hold are
 * refused with the reasons; what has no equivalent but loses nothing on the
 * invoice (the accountant's copy, the note) is a note on the entry.
 */
final readonly class RecurringStage implements ImportStage
{
    public const string KEY = 'recurring';
    public const string ADVANCE_UNSUPPORTED = 'idoklad.import.recurring.advance_unsupported';
    public const string UNKNOWN_TYPE = 'idoklad.import.recurring.unknown_type';
    public const string FOREIGN_CURRENCY = 'idoklad.import.recurring.foreign_currency';
    public const string INVALID_SCHEDULE = 'idoklad.import.recurring.invalid_schedule';
    public const string CUSTOMER_NOT_LINKED = 'idoklad.import.recurring.customer_not_linked';
    public const string SERIES_NOT_LINKED = 'idoklad.import.recurring.series_not_linked';
    public const string UNKNOWN_VARIABLE = 'idoklad.import.recurring.unknown_variable';
    public const string INVALID_ITEM = 'idoklad.import.recurring.invalid_item';
    public const string UNKNOWN_PAYMENT_OPTION = 'idoklad.import.recurring.unknown_payment_option';
    public const string UNSUPPORTED_LANGUAGE = 'idoklad.import.recurring.unsupported_language';
    public const string REVERSE_CHARGE_UNSUPPORTED = 'idoklad.import.recurring.reverse_charge_unsupported';
    public const string NEXT_ISSUE_MISMATCH = 'idoklad.import.recurring.next_issue_mismatch';
    /** Notes on an imported template. */
    public const string INACTIVE = 'idoklad.import.recurring.inactive';
    public const string ACCOUNTANT_NOT_SENT = 'idoklad.import.recurring.accountant_not_sent';
    public const string NOTE_NOT_CARRIED = 'idoklad.import.recurring.note_not_carried';
    public const string FIXED_VARIABLE_SYMBOL = 'idoklad.import.recurring.fixed_variable_symbol';
    public const string ROUNDING_ITEM_SKIPPED = 'idoklad.import.recurring.rounding_item_skipped';

    private const int ISSUED_INVOICE = 0;
    private const int PROFORMA_INVOICE = 1;

    private const array UNITS = [0 => RecurrenceUnit::Day, 1 => RecurrenceUnit::Week, 2 => RecurrenceUnit::Month, 3 => RecurrenceUnit::Year];
    private const array ENDS = [0 => RecurrenceEnd::Never, 1 => RecurrenceEnd::OnDate, 2 => RecurrenceEnd::AfterCount];
    private const array TAX_POINTS = [0 => TaxPointRule::IssueDate, 1 => TaxPointRule::LastDayOfMonth];
    private const array PRICE_TYPES = [0 => PriceType::WithVat, 1 => PriceType::WithoutVat, 2 => PriceType::BaseOnly];
    /** VatRateType: Reduced1, Basic, Zero, Reduced2; zero is an exempt supply here. */
    private const array RATE_KINDS = [0 => VatRateKind::Reduced, 1 => VatRateKind::Base, 2 => null, 3 => VatRateKind::SecondReduced];
    /** ReportLanguage: Cz, Sk, En, De; Foresi prints Czech and English. */
    private const array LANGUAGES = [1 => DocumentLanguage::Czech, 3 => DocumentLanguage::English];
    /** The payment options of iDoklad by their name, folded. */
    private const array PAYMENT_OPTIONS = [
        'převodem' => PaymentMethod::BankTransfer,
        'bankovní převod' => PaymentMethod::BankTransfer,
        'bank transfer' => PaymentMethod::BankTransfer,
        'hotově' => PaymentMethod::Cash,
        'hotovost' => PaymentMethod::Cash,
        'cash' => PaymentMethod::Cash,
        'kartou' => PaymentMethod::Card,
        'platební kartou' => PaymentMethod::Card,
        'card' => PaymentMethod::Card,
    ];
    private const int ITEM_NORMAL = 0;
    private const int ITEM_ROUNDING = 1;
    /** The variables iDoklad resolves in the texts of a template, which are Foresi's tokens. */
    private const string VARIABLE = '~^\{(?:(?:day|month|year|quarter|month/year)(?:[+-]\d{1,4})?|document_number)\}$~D';

    public function __construct(
        private SaveRecurringInvoice $save,
        private RecurringPreview $preview,
        private InvoicingCountries $countries,
        private FirmBankAccounts $accounts,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 400;
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->records(IdokladResource::RecurringInvoices) as $record) {
            $id = $record->externalId();
            $linked = $context->linkedTo(IdokladResource::RecurringInvoices, $id);
            if (null !== $linked) {
                $context->report(self::KEY, $id, ImportOutcome::AlreadyLinked, $linked);

                continue;
            }
            $payload = $record->payload();
            $template = \is_array($payload['InvoiceTemplate'] ?? null) ? $payload['InvoiceTemplate'] : [];
            $setting = \is_array($payload['RecurringSetting'] ?? null) ? $payload['RecurringSetting'] : [];
            $active = true === ($setting['IsActive'] ?? null);
            $values = [
                'name' => $this->text($setting['TemplateName'] ?? null),
                'document_type' => $this->int($template['DocumentType'] ?? null),
                'is_active' => $active,
                'idoklad_next_issue' => $this->date($setting['DateOfNextIssue'] ?? null)?->format('Y-m-d'),
            ];

            $documentType = $values['document_type'];
            if (self::ISSUED_INVOICE !== $documentType) {
                $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: [self::PROFORMA_INVOICE === $documentType ? self::ADVANCE_UNSUPPORTED : self::UNKNOWN_TYPE], values: $values);

                continue;
            }

            $notes = [];
            $refusals = [];
            $input = $this->input($context, $template, $setting, $refusals, $notes, $values);
            $history = new RecurringHistory((string) $id, max(0, $this->int($setting['CopyCountActual'] ?? null) ?? 0), $this->date($setting['DateOfLastIssue'] ?? null));
            if (!$input instanceof RecurringInput) {
                $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: array_values(array_unique($refusals)), values: $values);

                continue;
            }

            $next = RecurringInvoice::occurrenceAfter($input->recurrence, $history->issuedCount, $history->lastOccurrence);
            $values['next_issue'] = $next?->format('Y-m-d');
            $values['issued_count'] = $history->issuedCount;
            // An inactive iDoklad template has no next issue to compare; Foresi resumes it from the day it is resumed.
            if ($active && $values['next_issue'] !== $values['idoklad_next_issue']) {
                $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: [self::NEXT_ISSUE_MISMATCH], values: $values);

                continue;
            }
            if (!$active) {
                $notes[] = self::INACTIVE;
            }

            try {
                $saved = $this->save->import($input, $history);
            } catch (RecurringRefused $refused) {
                $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: array_values(array_unique(array_map(static fn (IssueProblem $problem): string => $problem->key, $refused->problems))), values: $values);

                continue;
            }
            $values['state'] = $saved->state()->value;
            if ($next instanceof \DateTimeImmutable) {
                $values['next_issues'] = implode(',', array_map(static fn (PreviewRow $row): string => $row->issue->format('Y-m-d'), $this->preview->next($input, $history->issuedCount, $next)));
            }
            $context->link(IdokladResource::RecurringInvoices, $id, $saved->id());
            $context->report(self::KEY, $id, ImportOutcome::Created, $saved->id(), $notes, $values);
        }
    }

    /**
     * The template as Foresi takes it; null when it is refused, for the reasons it adds.
     *
     * @param array<mixed>                        $template
     * @param array<mixed>                        $setting
     * @param list<string>                        $refusals
     * @param list<string>                        $notes
     * @param array<string, int|string|bool|null> $values
     *
     * @param-out list<string> $refusals
     * @param-out list<string> $notes
     */
    private function input(ImportContext $context, array $template, array $setting, array &$refusals, array &$notes, array &$values): ?RecurringInput
    {
        $firm = $context->firm;
        try {
            $currency = $this->countries->for($firm->address()->countryCode)->homeCurrency();
        } catch (UnsupportedCountry) {
            $currency = null;
        }
        $code = $this->text($this->code($context, IdokladResource::Currencies, $template['CurrencyId'] ?? null)['Code'] ?? null);
        $values['currency'] = $code;
        if (!$currency instanceof Currency || $code !== $currency->value) {
            $refusals[] = self::FOREIGN_CURRENCY;
        }
        if (null !== ($template['VatReverseChargeCodeId'] ?? null)) {
            $refusals[] = self::REVERSE_CHARGE_UNSUPPORTED;
        }

        $customerId = $this->int(\is_array($template['Partner'] ?? null) ? ($template['Partner']['Id'] ?? null) : null) ?? $this->int($template['PartnerId'] ?? null);
        $customer = null === $customerId ? null : $context->linkedTo(IdokladResource::Contacts, $customerId);
        if (null === $customer) {
            $refusals[] = self::CUSTOMER_NOT_LINKED;
        }
        $sequenceId = $this->int($template['NumericSequenceId'] ?? null);
        $series = null === $sequenceId ? null : $context->linkedTo(IdokladResource::NumericSequences, $sequenceId);
        if (null !== $sequenceId && null === $series) {
            $refusals[] = self::SERIES_NOT_LINKED;
        }

        $language = self::LANGUAGES[$this->int($template['ReportLanguage'] ?? null) ?? -1] ?? null;
        if (!$language instanceof DocumentLanguage) {
            $refusals[] = self::UNSUPPORTED_LANGUAGE;
        }
        $option = $this->code($context, IdokladResource::PaymentOptions, $template['PaymentOptionId'] ?? null);
        $method = self::PAYMENT_OPTIONS[mb_strtolower(trim($this->text($option['Name'] ?? null)))] ?? null;
        $values['payment_option'] = $this->text($option['Name'] ?? null);
        if (!$method instanceof PaymentMethod) {
            $refusals[] = self::UNKNOWN_PAYMENT_OPTION;
        }

        $texts = [
            'OrderNumber' => $this->text($template['OrderNumber'] ?? null),
            'Description' => $this->text($template['Description'] ?? null),
            'ItemsTextPrefix' => $this->text($template['ItemsTextPrefix'] ?? null),
            'ItemsTextSuffix' => $this->text($template['ItemsTextSuffix'] ?? null),
        ];
        $lines = $this->lines($template, $currency ?? Currency::CZK, VatRegime::of($firm->vatStatus()), $refusals, $notes, $texts);
        $unknown = $this->unknownVariables($texts);
        if ([] !== $unknown) {
            $refusals[] = self::UNKNOWN_VARIABLE;
            $values['unknown_variables'] = implode(' ', $unknown);
        }

        if ('' !== trim($this->text($template['Note'] ?? null))) {
            $notes[] = self::NOTE_NOT_CARRIED;
        }
        if (true === ($template['IsConstantVariableSymbol'] ?? null)) {
            $notes[] = self::FIXED_VARIABLE_SYMBOL;
        }
        if (true === ($setting['SendToAccountant'] ?? null)) {
            $notes[] = self::ACCOUNTANT_NOT_SENT;
        }

        $recurrence = $this->recurrence($setting);
        $taxPoint = self::TAX_POINTS[$this->int($template['TaxingType'] ?? null) ?? -1] ?? null;
        if (!$recurrence instanceof Recurrence || !$taxPoint instanceof TaxPointRule) {
            $refusals[] = self::INVALID_SCHEDULE;
        }
        if ([] !== $refusals || null === $customer || !$language instanceof DocumentLanguage || !$method instanceof PaymentMethod || !$recurrence instanceof Recurrence || !$taxPoint instanceof TaxPointRule) {
            return null;
        }

        return new RecurringInput(
            $firm->id(),
            $series,
            $customer,
            $method,
            PaymentMethod::BankTransfer === $method ? $this->bankAccount($context, $template['BankAccountId'] ?? null) : null,
            $this->text($this->code($context, IdokladResource::ConstantSymbols, $template['ConstantSymbolId'] ?? null)['Code'] ?? null),
            $texts['OrderNumber'],
            $texts['Description'],
            $texts['ItemsTextPrefix'],
            $texts['ItemsTextSuffix'],
            $language,
            $lines,
            $recurrence,
            $taxPoint,
            $this->int($template['InvoiceMaturity'] ?? null) ?? 0,
            true === ($setting['SendToPurchaser'] ?? null) ? RecurringDelivery::Send : RecurringDelivery::Review,
            mb_substr(trim($this->text($setting['TemplateName'] ?? null)), 0, RecurringInvoice::MAX_NAME_LENGTH),
            true === ($setting['SendToSupplier'] ?? null),
            true === ($setting['SendToOtherEmails'] ?? null),
        );
    }

    /**
     * @param array<mixed> $setting
     */
    private function recurrence(array $setting): ?Recurrence
    {
        $unit = self::UNITS[$this->int($setting['RecurrenceType'] ?? null) ?? -1] ?? null;
        $end = self::ENDS[$this->int($setting['TypeOfEnd'] ?? null) ?? -1] ?? null;
        $start = $this->date($setting['DateOfStart'] ?? null);
        $every = $this->int($setting['RecurrenceCount'] ?? null);
        if (!$unit instanceof RecurrenceUnit || !$end instanceof RecurrenceEnd || !$start instanceof \DateTimeImmutable || null === $every) {
            return null;
        }

        try {
            return new Recurrence(
                $start,
                $unit,
                $every,
                true === ($setting['IssueLastDayOfMonth'] ?? null) && \in_array($unit, [RecurrenceUnit::Month, RecurrenceUnit::Year], true),
                $end,
                RecurrenceEnd::OnDate === $end ? $this->date($setting['DateOfEnd'] ?? null) : null,
                RecurrenceEnd::AfterCount === $end ? $this->int($setting['CopyCountEnd'] ?? null) : null,
            );
        } catch (InvalidRecurrence) {
            return null;
        }
    }

    /**
     * The items as lines; the item names join the texts whose variables are checked.
     *
     * @param array<mixed>          $template
     * @param list<string>          $refusals
     * @param list<string>          $notes
     * @param array<string, string> $texts
     *
     * @param-out list<string> $refusals
     * @param-out list<string> $notes
     *
     * @return list<LineInput>
     */
    private function lines(array $template, Currency $currency, VatRegime $regime, array &$refusals, array &$notes, array &$texts): array
    {
        $discount = $this->decimal($template['DiscountPercentage'] ?? 0, 2);
        $items = \is_array($template['Items'] ?? null) ? array_values(array_filter($template['Items'], \is_array(...))) : [];
        $lines = [];
        foreach ($items as $index => $item) {
            $type = $this->int($item['ItemType'] ?? self::ITEM_NORMAL);
            if (self::ITEM_ROUNDING === $type) {
                // Foresi rounds the total by the firm's own setting.
                $notes[] = self::ROUNDING_ITEM_SKIPPED;

                continue;
            }
            $name = $this->text($item['Name'] ?? null);
            $texts['Items.'.$index] = $name;
            $line = self::ITEM_NORMAL === $type ? $this->line($item, $name, $discount, $currency, $regime) : null;
            if (!$line instanceof LineInput) {
                $refusals[] = self::INVALID_ITEM;

                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param array<mixed> $item
     */
    private function line(array $item, string $name, ?string $discount, Currency $currency, VatRegime $regime): ?LineInput
    {
        $quantity = $this->decimal($item['Amount'] ?? null, 4);
        $price = $this->decimal(\is_array($item['Prices'] ?? null) ? ($item['Prices']['UnitPrice'] ?? null) : null, 2);
        $priceType = self::PRICE_TYPES[$this->int($item['PriceType'] ?? null) ?? -1] ?? null;
        $rateType = $this->int($item['VatRateType'] ?? null) ?? -1;
        if (in_array(null, [$quantity, $price, $discount], true) || !$priceType instanceof PriceType || !\array_key_exists($rateType, self::RATE_KINDS)) {
            return null;
        }
        $kind = self::RATE_KINDS[$rateType];
        [$treatment, $kind] = match (true) {
            VatRegime::NonPayer === $regime => [TaxTreatment::NotApplicable, null],
            null === $kind => [TaxTreatment::Exempt, null],
            default => [TaxTreatment::Standard, $kind],
        };

        try {
            return new LineInput($name, Quantity::fromString($quantity), $this->text($item['Unit'] ?? null), Money::of($price, $currency), $priceType, $treatment, $kind, null, Percent::fromString($discount));
        } catch (\InvalidArgumentException|\OverflowException) {
            return null;
        }
    }

    /**
     * The variables of the texts that are not iDoklad's, which Foresi would print as typed.
     *
     * @param array<string, string> $texts
     *
     * @return list<string>
     */
    private function unknownVariables(array $texts): array
    {
        $unknown = [];
        foreach ($texts as $text) {
            preg_match_all('~\{[^{}]*\}~', $text, $found);
            foreach ($found[0] as $variable) {
                if (1 !== preg_match(self::VARIABLE, $variable)) {
                    $unknown[] = $variable;
                }
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * The IBAN of the iDoklad bank account, or the firm's primary account when iDoklad names none.
     */
    private function bankAccount(ImportContext $context, mixed $bankAccountId): ?string
    {
        $iban = preg_replace('/\s+/', '', $this->text($this->code($context, IdokladResource::BankAccounts, $bankAccountId)['Iban'] ?? null));
        if ('' !== $iban && null !== $iban) {
            return $iban;
        }
        $primary = $this->accounts->primaryOf($context->firm);

        return $primary?->iban();
    }

    /**
     * @return array<mixed>
     */
    private function code(ImportContext $context, IdokladResource $resource, mixed $id): array
    {
        return \is_int($id) ? ($context->record($resource, $id)?->payload() ?? []) : [];
    }

    /**
     * A JSON number as a decimal with at most $scale decimals; null when it has more or is none.
     */
    private function decimal(mixed $value, int $scale): ?string
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        if (!\is_float($value) || !is_finite($value)) {
            return null;
        }
        $decimal = rtrim(rtrim(number_format($value, $scale + 2, '.', ''), '0'), '.');
        $point = strpos($decimal, '.');

        return false !== $point && \strlen($decimal) - $point - 1 > $scale ? null : $decimal;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $day)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day[1]);

        return false === $date || $date->format('Y-m-d') !== $day[1] ? null : $date;
    }

    private function int(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
