<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Form;

use App\Party\Domain\Party;
use App\Purchasing\Application\Query\SupplierChoices;
use App\Purchasing\Application\Query\SupplierOption;
use App\Purchasing\Application\ReceivedDocumentInput;
use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\VatRate;
use App\Purchasing\Domain\VatRecap;
use App\Purchasing\Domain\VatRecapLine;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form of a received document as text: read from the post, written from a
 * stored document, and turned into what the use cases take. Numbers are read
 * as the user's language writes them. A rate the person left as it was shown
 * is sent again only while the currency and the day it was for stay; once the
 * tax point (or the issue date) changes, no rate is sent, so the published one
 * of the new day is filled in. A supplier typed without JavaScript is the one
 * contact the text finds.
 *
 * @phpstan-type Row array{rate: string, base: string, vat: string, statedVatCzk: string}
 * @phpstan-type Values array{kind: string, treatment: string, supplierId: string, supplierQuery: string, supplierNumber: string, variableSymbol: string, issueDate: string, taxPointDate: string, dueDate: string, receivedDate: string, currency: string, total: string, exchangeRate: string, rateShown: string, rateFor: string, rateDate: string, supplyCode: string, originalDocumentId: string, recap: list<Row>}
 */
final readonly class ReceivedDocumentForm
{
    private const int MAX_TEXT = 255;

    public function __construct(private PurchasingText $text, private SupplierChoices $suppliers)
    {
    }

    /**
     * A new document received today, in CZK, with one empty row of the recap.
     *
     * @return Values
     */
    public function blank(\DateTimeImmutable $today): array
    {
        return [
            'kind' => ReceivedDocumentKind::TaxInvoice->value,
            'treatment' => PurchaseTaxTreatment::Domestic->value,
            'supplierId' => '',
            'supplierQuery' => '',
            'supplierNumber' => '',
            'variableSymbol' => '',
            'issueDate' => '',
            'taxPointDate' => '',
            'dueDate' => '',
            'receivedDate' => $today->format('Y-m-d'),
            'currency' => Currency::CZK->value,
            'total' => '',
            'exchangeRate' => '',
            'rateShown' => '',
            'rateFor' => '',
            'rateDate' => '',
            'supplyCode' => '',
            'originalDocumentId' => '',
            'recap' => [self::emptyRow()],
        ];
    }

    /**
     * @return Values
     */
    public function of(ReceivedDocument $document): array
    {
        $rate = Currency::CZK === $document->currency() ? '' : $this->text->decimal($document->exchangeRate()?->toString() ?? '');
        $rows = [];
        foreach ($document->vatRecap()->rows() as $row) {
            $rows[] = [
                'rate' => $this->text->decimal($row->rate->percent()),
                'base' => $this->text->field($row->base),
                'vat' => $this->text->field($row->vat),
                'statedVatCzk' => $this->text->field($row->statedVatCzk),
            ];
        }

        return [
            'kind' => $document->kind()->value,
            'treatment' => $document->treatment()->value,
            'supplierId' => $document->supplierParty()?->id() ?? '',
            'supplierQuery' => $document->supplierParty() instanceof Party ? $document->supplierParty()->displayName() : '',
            'supplierNumber' => $document->supplierNumber(),
            'variableSymbol' => $document->variableSymbol(),
            'issueDate' => $document->issueDate()?->format('Y-m-d') ?? '',
            'taxPointDate' => $document->taxPointDate()?->format('Y-m-d') ?? '',
            'dueDate' => $document->dueDate()?->format('Y-m-d') ?? '',
            'receivedDate' => $document->receivedDate()?->format('Y-m-d') ?? '',
            'currency' => $document->currency()->value,
            'total' => $this->text->field($document->total()),
            'exchangeRate' => $rate,
            'rateShown' => $rate,
            'rateFor' => self::rateFor($document->currency()->value, $document->taxPointDate()?->format('Y-m-d') ?? '', $document->issueDate()?->format('Y-m-d') ?? ''),
            'rateDate' => $document->exchangeRateDate()?->format('Y-m-d') ?? '',
            'supplyCode' => $document->supplyCode() ?? '',
            'originalDocumentId' => $document->originalDocumentId() ?? '',
            'recap' => [] === $rows ? [self::emptyRow()] : $rows,
        ];
    }

    /**
     * What was posted, every value a string; a row of the recap left empty stays, for the person to fill.
     *
     * @return Values
     */
    public function posted(Request $request): array
    {
        $text = static fn (mixed $value, int $length): string => \is_string($value) ? mb_substr(trim($value), 0, $length) : '';
        $field = static fn (string $name): string => $text($request->request->all()[$name] ?? null, self::MAX_TEXT);
        $rows = [];
        foreach (\array_slice($request->request->all('recap'), 0, VatRecap::MAX_ROWS) as $row) {
            if (\is_array($row)) {
                $rows[] = ['rate' => $text($row['rate'] ?? null, 32), 'base' => $text($row['base'] ?? null, 32), 'vat' => $text($row['vat'] ?? null, 32), 'statedVatCzk' => $text($row['statedVatCzk'] ?? null, 32)];
            }
        }

        return [
            'kind' => $field('kind'),
            'treatment' => $field('treatment'),
            'supplierId' => $field('supplierId'),
            'supplierQuery' => $field('supplierQuery'),
            'supplierNumber' => $field('supplierNumber'),
            'variableSymbol' => $field('variableSymbol'),
            'issueDate' => $field('issueDate'),
            'taxPointDate' => $field('taxPointDate'),
            'dueDate' => $field('dueDate'),
            'receivedDate' => $field('receivedDate'),
            'currency' => $field('currency'),
            'total' => $field('total'),
            'exchangeRate' => $field('exchangeRate'),
            'rateShown' => $field('rateShown'),
            'rateFor' => $field('rateFor'),
            'rateDate' => $field('rateDate'),
            'supplyCode' => $field('supplyCode'),
            'originalDocumentId' => $field('originalDocumentId'),
            'recap' => [] === $rows ? [self::emptyRow()] : $rows,
        ];
    }

    /**
     * The values after a button that changes the form only: a row added or removed.
     *
     * @param Values $values
     *
     * @return Values
     */
    public function changed(array $values, string $action): array
    {
        if ('add_row' === $action && \count($values['recap']) < VatRecap::MAX_ROWS) {
            $values['recap'][] = self::emptyRow();
        }
        if (1 === preg_match('/^remove_row:(\d+)$/D', $action, $match)) {
            unset($values['recap'][(int) $match[1]]);
            $values['recap'] = array_values($values['recap']);
            if ([] === $values['recap']) {
                $values['recap'] = [self::emptyRow()];
            }
        }

        return $values;
    }

    /**
     * What the use cases take, or the fields that cannot be read, each with the
     * translation key of its message, by the id of its input.
     *
     * @param Values $values
     *
     * @return array{?ReceivedDocumentInput, array<string, string>}
     */
    public function input(array $values): array
    {
        $errors = [];
        $kind = ReceivedDocumentKind::tryFrom($values['kind']) ?? ReceivedDocumentKind::TaxInvoice;
        $treatment = PurchaseTaxTreatment::tryFrom($values['treatment']) ?? PurchaseTaxTreatment::Domestic;
        $currency = Currency::tryFrom($values['currency']) ?? Currency::CZK;
        $dates = [];
        foreach (['issueDate', 'taxPointDate', 'dueDate', 'receivedDate'] as $field) {
            $dates[$field] = $this->date($values[$field]);
            if (false === $dates[$field]) {
                $errors[$field] = 'purchasing.form.error.date';
            }
        }
        $total = $this->text->typedAmount($values['total'], $currency);
        if (false === $total) {
            $errors['total'] = 'purchasing.form.error.amount';
        }
        [$rate, $rateDate, $rateError] = $this->rate($values, $currency);
        if (null !== $rateError) {
            $errors['exchangeRate'] = $rateError;
        }
        [$supplierId, $supplierError] = $this->supplier($values);
        if (null !== $supplierError) {
            $errors['supplier'] = $supplierError;
        }
        $recap = [];
        foreach ($values['recap'] as $index => $row) {
            $line = $this->line($row, $index, $currency, $errors);
            if ($line instanceof VatRecapLine) {
                $recap[] = $line;
            }
        }
        if ([] !== $errors) {
            return [null, $errors];
        }
        $date = static fn (string $field): ?\DateTimeImmutable => $dates[$field] instanceof \DateTimeImmutable ? $dates[$field] : null;

        return [new ReceivedDocumentInput(
            $kind,
            $treatment,
            $supplierId,
            $values['supplierNumber'],
            $values['variableSymbol'],
            $date('issueDate'),
            $date('taxPointDate'),
            $date('dueDate'),
            $date('receivedDate'),
            $currency,
            $total instanceof Money ? $total : null,
            $rate,
            $rateDate,
            $recap,
            ReceivedDocumentKind::CreditNote === $kind && '' !== $values['originalDocumentId'] ? $values['originalDocumentId'] : null,
            PurchaseTaxTreatment::DomesticReverseCharge === $treatment && '' !== $values['supplyCode'] ? $values['supplyCode'] : null,
        ), []];
    }

    /**
     * The currency and the day a rate shown was for: the tax point, else the issue date.
     */
    public static function rateFor(string $currency, string $taxPointDate, string $issueDate): string
    {
        return $currency.'|'.('' === $taxPointDate ? $issueDate : $taxPointDate);
    }

    /**
     * @return Row
     */
    public static function emptyRow(): array
    {
        return ['rate' => '', 'base' => '', 'vat' => '', 'statedVatCzk' => ''];
    }

    private function date(string $value): \DateTimeImmutable|false|null
    {
        if ('' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : false;
    }

    /**
     * @param Values $values
     *
     * @return array{?ExchangeRate, ?\DateTimeImmutable, ?string}
     */
    private function rate(array $values, Currency $currency): array
    {
        $typed = $values['exchangeRate'];
        if (Currency::CZK === $currency || '' === $typed) {
            return [null, null, null];
        }
        $unchanged = $typed === $values['rateShown'];
        if ($unchanged && $values['rateFor'] !== self::rateFor($currency->value, $values['taxPointDate'], $values['issueDate'])) {
            // Shown for another day or currency: the use case takes the published rate of the new one.
            return [null, null, null];
        }
        try {
            $rate = ExchangeRate::of($this->text->typedDecimal($typed));
        } catch (ReceivedDocumentRefused) {
            return [null, null, 'purchasing.form.error.rate'];
        }
        $published = $unchanged ? $this->date($values['rateDate']) : null;

        return [$rate, $published instanceof \DateTimeImmutable ? $published : null, null];
    }

    /**
     * @param Values $values
     *
     * @return array{?string, ?string}
     */
    private function supplier(array $values): array
    {
        if ('' !== $values['supplierId'] || '' === $values['supplierQuery']) {
            return ['' === $values['supplierId'] ? null : $values['supplierId'], null];
        }
        // Without JavaScript the combobox sends the text alone.
        $found = $this->suppliers->search($values['supplierQuery']);
        if (1 === \count($found)) {
            return [$found[0]->id, null];
        }
        $exact = array_values(array_filter($found, static fn (SupplierOption $option): bool => mb_strtolower($option->name) === mb_strtolower($values['supplierQuery'])));

        return 1 === \count($exact) ? [$exact[0]->id, null] : [null, 'purchasing.form.error.supplier'];
    }

    /**
     * @param Row                   $row
     * @param array<string, string> $errors
     */
    private function line(array $row, int $index, Currency $currency, array &$errors): ?VatRecapLine
    {
        if ('' === implode('', $row)) {
            return null;
        }
        $id = 'recap-'.$index.'-';
        $rate = null;
        try {
            $rate = '' === $row['rate'] ? null : VatRate::of($this->text->typedDecimal($row['rate']));
        } catch (ReceivedDocumentRefused) {
        }
        if (!$rate instanceof VatRate) {
            $errors[$id.'rate'] = 'purchasing.form.error.vat_rate';
        }
        $base = $this->text->typedAmount($row['base'], $currency);
        if (!$base instanceof Money) {
            $errors[$id.'base'] = null === $base ? 'purchasing.form.error.required' : 'purchasing.form.error.amount';
        }
        $vat = $this->text->typedAmount($row['vat'], $currency);
        if (false === $vat) {
            $errors[$id.'vat'] = 'purchasing.form.error.amount';
        }
        $stated = $this->text->typedAmount($row['statedVatCzk'], Currency::CZK);
        if (false === $stated) {
            $errors[$id.'statedVatCzk'] = 'purchasing.form.error.amount';
        }
        if (!$rate instanceof VatRate || !$base instanceof Money || false === $vat || false === $stated) {
            return null;
        }

        return new VatRecapLine($rate, $base, $vat ?? Money::ofMinor(0, $currency), $stated);
    }
}
