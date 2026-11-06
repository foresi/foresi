<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportOutcome;
use App\Invoicing\Application\ImportedPaymentInput;
use App\Invoicing\Application\ImportOutcome as PaymentOutcome;
use App\Invoicing\Application\ImportPayment;
use App\Invoicing\Application\InvoicingCountries;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Money;

/**
 * The payments of issued invoices and proformas as manual payments of the
 * documents they paid, on their days, in the order they came; then, through the
 * documents stage, the tax documents on those payments and the invoices that
 * deduct advances, which need them. A payment issues no tax document here and
 * sends nothing: iDoklad's tax document is imported in its place. Payments in
 * another currency, of other documents, or that the invoicing import refuses
 * are reported with reasons; no bank movement is made.
 */
final readonly class PaymentsStage implements ImportStage
{
    public const string KEY = 'payments';
    public const string DOCUMENT_TYPE_UNSUPPORTED = 'idoklad.import.payments.document_type_unsupported';
    public const string DOCUMENT_NOT_LINKED = 'idoklad.import.payments.document_not_linked';
    public const string CURRENCY_NOT_HOME = 'idoklad.import.payments.currency_not_home';
    public const string PAYMENT_OPTION_UNSUPPORTED = 'idoklad.import.payments.payment_option_unsupported';
    public const string INVALID_VALUE = 'idoklad.import.payments.invalid_value';

    /** iDoklad's document types of a payment, as the resources of the documents. */
    private const array DOCUMENTS = [0 => IdokladResource::IssuedInvoices, 1 => IdokladResource::ProformaInvoices];

    public function __construct(
        private ImportPayment $import,
        private DocumentsStage $documents,
        private InvoicingCountries $countries,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 350;
    }

    public function run(ImportContext $context): void
    {
        $records = [...$context->records(IdokladResource::IssuedDocumentPayments)];
        $key = fn (IdokladRecord $record): string => substr($this->text($record->payload()['DateOfPayment'] ?? null), 0, 10).' '.str_pad((string) $record->externalId(), 19, '0', \STR_PAD_LEFT);
        usort($records, static fn (IdokladRecord $a, IdokladRecord $b): int => strcmp($key($a), $key($b)));
        foreach ($records as $record) {
            $this->payment($context, $record);
        }
        $this->documents->taxDocuments($context);
        $this->documents->finalInvoices($context);
    }

    private function payment(ImportContext $context, IdokladRecord $record): void
    {
        $id = $record->externalId();
        $payload = $record->payload();
        $values = [
            'document_type' => \is_int($payload['DocumentType'] ?? null) ? $payload['DocumentType'] : null,
            'document_id' => \is_int($payload['InvoiceId'] ?? null) ? $payload['InvoiceId'] : null,
            'document_number' => $this->text($payload['InvoiceDocumentNumber'] ?? null),
        ];
        $linked = $context->linkedTo(IdokladResource::IssuedDocumentPayments, $id);
        if (null !== $linked) {
            $context->report(self::KEY, $id, ImportOutcome::AlreadyLinked, $linked, values: $values);

            return;
        }
        $reasons = [];
        $input = $this->input($context, $record, $values, $reasons);
        if (!$input instanceof ImportedPaymentInput) {
            $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: $reasons, values: $values);

            return;
        }
        $result = $this->import->import($input);
        if (null === $result->paymentId) {
            $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: ['payment.refused.'.$result->reason], values: $values);

            return;
        }
        // Imported before but no longer linked: the link is made again.
        $context->link(IdokladResource::IssuedDocumentPayments, $id, $result->paymentId);
        $context->report(self::KEY, $id, PaymentOutcome::Imported === $result->outcome ? ImportOutcome::Created : ImportOutcome::Matched, $result->paymentId, values: $values);
    }

    /**
     * @param array<string, int|string|bool|null> $values  filled with what explains it
     * @param list<string>                        $reasons filled with why not, when null
     */
    private function input(ImportContext $context, IdokladRecord $record, array &$values, array &$reasons): ?ImportedPaymentInput
    {
        $payload = $record->payload();
        $resource = self::DOCUMENTS[$values['document_type'] ?? -1] ?? null;
        if (!$resource instanceof IdokladResource) {
            $reasons = [self::DOCUMENT_TYPE_UNSUPPORTED];

            return null;
        }
        $invoiceId = $context->linkedTo($resource, \is_int($values['document_id']) ? $values['document_id'] : 0);
        $home = $this->countries->for($context->firm->address()->countryCode)->homeCurrency();
        $currency = $context->record(IdokladResource::Currencies, \is_int($payload['CurrencyId'] ?? null) ? $payload['CurrencyId'] : 0)?->payload()['Code'] ?? null;
        $method = $this->method($context, $payload['PaymentOptionId'] ?? null, $values);
        $amount = \is_array($payload['Prices'] ?? null) ? $payload['Prices']['PaymentAmount'] ?? null : null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($this->text($payload['DateOfPayment'] ?? null), 0, 10));
        if ($home->value !== $currency) {
            $values['currency'] = \is_string($currency) ? $currency : null;
        }
        $reasons = [
            ...null === $invoiceId ? [self::DOCUMENT_NOT_LINKED] : [],
            ...$home->value === $currency ? [] : [self::CURRENCY_NOT_HOME],
            ...$method instanceof PaymentMethod ? [] : [self::PAYMENT_OPTION_UNSUPPORTED],
            ...(\is_int($amount) || \is_float($amount)) && false !== $date ? [] : [self::INVALID_VALUE],
        ];
        if ([] !== $reasons || null === $invoiceId || !$method instanceof PaymentMethod || !\is_int($amount) && !\is_float($amount) || false === $date) {
            return null;
        }

        return new ImportedPaymentInput(
            $context->firm->id(),
            $invoiceId,
            $date,
            Money::of(\sprintf('%.4F', $amount), $home),
            $method,
            '',
            DocumentsStage::SOURCE,
            $record->resource()->value.'/'.$record->externalId(),
            $context->runId,
        );
    }

    /**
     * @param array<string, int|string|bool|null> $values
     */
    private function method(ImportContext $context, mixed $optionId, array &$values): ?PaymentMethod
    {
        $name = $this->text($context->record(IdokladResource::PaymentOptions, \is_int($optionId) ? $optionId : 0)?->payload()['Name'] ?? null);
        foreach (DocumentsStage::PAYMENT_WORDS as $word => $method) {
            if (str_contains(mb_strtolower($name), $word)) {
                return $method;
            }
        }
        $values['payment_option'] = $name;

        return null;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
