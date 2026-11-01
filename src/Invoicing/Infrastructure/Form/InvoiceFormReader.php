<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VariableSymbol;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Countries;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the invoice form before anything is written: an expected mistake is a form
 * error, never an exception out of a write. People write a decimal comma and spaces
 * between thousands. What is too long is refused, here or by IssueRules, and never
 * cut, and an amount is never rounded to fit.
 */
final readonly class InvoiceFormReader
{
    public const int MAX_TEXT = 2000;

    /** With eight fields a line this stays well under the 1000 of PHP's max_input_vars. */
    public const int MAX_LINES = 100;

    /** A valid id of nothing: the rules answer it as unknown on either database. */
    public const string NO_ID = '00000000-0000-7000-8000-000000000000';

    /** The choices of the VAT cell; "none" is the only one of a form without VAT. */
    public const string VAT_BASE = 'base';
    public const string VAT_REDUCED = 'reduced';
    public const string VAT_REVERSE_CHARGE = 'reverse_charge';
    public const string VAT_EXEMPT = 'exempt';
    public const string VAT_NONE = 'none';

    private const string PRICE = '/^-?\d{1,13}(?:\.\d{1,2})?$/D';
    private const string QUANTITY = '/^-?\d{1,9}(?:\.\d{1,4})?$/D';
    private const string DISCOUNT = '/^\d{1,3}(?:\.\d{1,2})?$/D';
    private const string DATE = '/^20\d{2}-\d{2}-\d{2}$/D';
    private const array LINE_FIELDS = ['text', 'price', 'quantity', 'unit', 'priceType', 'vat', 'supplyCode', 'discount'];
    private const array CUSTOMER_FIELDS = ['name', 'registrationNumber', 'vatId', 'street', 'city', 'postalCode', 'countryCode', 'email', 'phone', 'web', 'registerEntry'];

    public function read(Request $request, Currency $currency): InvoiceSubmission
    {
        $post = $request->request->all();
        $rows = $this->withoutEmptyRows($this->rows($post));
        $lines = $this->linesOf($rows, $currency);
        $customer = $this->customer($post);
        $values = $this->values($post, $rows, $customer);
        // Only the document takes it: on the form an empty description keeps following the first line.
        $description = '' === $values['description'] && [] !== $rows ? $rows[0]['text'] : $values['description'];

        $errors = $lines->errors;
        foreach (['textBefore' => $values['textBefore'], 'textAfter' => $values['textAfter'], 'description' => $description] as $field => $text) {
            if (mb_strlen($text) > self::MAX_TEXT) {
                $errors[$field] = 'invoice.problem.too_long';
            }
        }
        if (\count($rows) > self::MAX_LINES) {
            $errors['lines'] = 'invoice.problem.too_many_lines';
        }
        if ('1' === $values['customerEdit']) {
            $this->checkCopy($customer, $errors);
        }
        $issueDate = $this->date($values['issueDate'], 'issueDate', $errors);
        if ('' === $values['issueDate']) {
            $errors['issueDate'] = 'invoice.problem.issue_date_missing';
        }
        $taxPoint = $this->date($values['taxPointDate'], 'taxPointDate', $errors);
        $dueDate = $this->date($values['dueDate'], 'dueDate', $errors);
        $method = PaymentMethod::tryFrom($values['paymentMethod']);
        if (!$method instanceof PaymentMethod) {
            $errors['paymentMethod'] = 'invoice.problem.payment_method_invalid';
        }
        $language = DocumentLanguage::tryFrom($values['language']);
        if (!$language instanceof DocumentLanguage) {
            $errors['language'] = 'invoice.problem.language_invalid';
        }

        $number = $values['number'];
        $peek = $values['numberPeek'];
        $input = new InvoiceInput(
            $values['firmId'],
            $this->seriesId($values['seriesId']),
            '' === $number || $number === $peek ? null : $number,
            $this->id($values['customerId']),
            '1' === $values['customerEdit'] ? $this->copy($customer) : null,
            $issueDate ?? new \DateTimeImmutable('today'),
            $taxPoint,
            $dueDate,
            $method ?? PaymentMethod::BankTransfer,
            '' === $values['bankAccount'] ? null : $values['bankAccount'],
            $values['variableSymbol'],
            $values['constantSymbol'],
            $values['orderNumber'],
            $description,
            $values['textBefore'],
            $values['textAfter'],
            $language ?? DocumentLanguage::Czech,
            $lines->lines,
            '' !== $peek && $values['variableSymbol'] === VariableSymbol::fromNumber($peek),
        );
        $version = $this->text($post, 'version');

        return new InvoiceSubmission($values, $errors, $input, ctype_digit($version) ? (int) $version : 0);
    }

    /**
     * PHP drops what lies past max_input_vars without a word, so the form ends with a
     * field of its own: without it, the end of the form did not arrive.
     */
    public function isComplete(Request $request): bool
    {
        return '1' === $request->request->getString('_complete');
    }

    /**
     * Every row counts here, so the answer goes row for row with the form.
     */
    public function lines(Request $request, Currency $currency): InvoiceLinesSubmission
    {
        $post = $request->request->all();

        return $this->linesOf($this->rows($post), $currency);
    }

    /**
     * @param array<string, string> $errors
     */
    public function date(string $value, string $field, array &$errors): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        $date = 1 === preg_match(self::DATE, $value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            $errors[$field] = 'invoice.problem.date_invalid';

            return null;
        }

        return $date;
    }

    /**
     * @param array<string, mixed>        $post
     * @param list<array<string, string>> $rows
     * @param array<string, string>       $customer
     *
     * @return array{firmId: string, firmShown: string, seriesId: string, number: string, numberPeek: string, customerId: string, customerQuery: string, customerEdit: string, dueDateSet: string, issueDate: string, taxPointDate: string, dueDate: string, paymentMethod: string, bankAccount: string, variableSymbol: string, constantSymbol: string, orderNumber: string, description: string, textBefore: string, textAfter: string, language: string, customer: array<string, string>, lines: list<array<string, string>>}
     */
    private function values(array $post, array $rows, array $customer): array
    {
        $field = fn (string $name): string => trim($this->text($post, $name));

        return [
            'firmId' => $field('firmId'), 'firmShown' => $field('firmShown'), 'seriesId' => $field('seriesId'), 'number' => $field('number'), 'numberPeek' => $field('numberPeek'),
            'customerId' => $field('customerId'), 'customerQuery' => $field('customerQuery'), 'customerEdit' => $field('customerEdit'), 'dueDateSet' => $field('dueDateSet'),
            'issueDate' => $field('issueDate'), 'taxPointDate' => $field('taxPointDate'), 'dueDate' => $field('dueDate'), 'paymentMethod' => $field('paymentMethod'), 'bankAccount' => $field('bankAccount'),
            'variableSymbol' => $field('variableSymbol'), 'constantSymbol' => $field('constantSymbol'), 'orderNumber' => $field('orderNumber'), 'description' => $field('description'),
            'textBefore' => $field('textBefore'), 'textAfter' => $field('textAfter'), 'language' => $field('language'), 'customer' => $customer, 'lines' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return list<array<string, string>>
     */
    private function rows(array $post): array
    {
        $lines = $post['lines'] ?? [];
        $rows = [];
        foreach (\is_array($lines) ? $lines : [] as $line) {
            if (\is_array($line)) {
                $row = [];
                foreach (self::LINE_FIELDS as $field) {
                    $value = $line[$field] ?? '';
                    $row[$field] = \is_string($value) ? trim($value) : '';
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Rows nobody filled in are not lines; the first stays when all are empty, so
     * the form keeps a row and the rules can ask for it.
     *
     * @param list<array<string, string>> $rows
     *
     * @return list<array<string, string>>
     */
    private function withoutEmptyRows(array $rows): array
    {
        $filled = array_values(array_filter($rows, static fn (array $row): bool => '' !== $row['text'].$row['price'].$row['unit'].$row['discount']));

        return [] === $filled ? \array_slice($rows, 0, 1) : $filled;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function linesOf(array $rows, Currency $currency): InvoiceLinesSubmission
    {
        $errors = [];
        $lines = [];
        foreach ($rows as $index => $row) {
            $field = 'lines.'.$index.'.';
            [$treatment, $kind] = $this->vat($row['vat']) ?? [TaxTreatment::Standard, VatRateKind::Base];
            if (null === $this->vat($row['vat'])) {
                $errors[$field.'vat'] = 'invoice.problem.vat_invalid';
            }
            $priceType = PriceType::tryFrom($row['priceType']);
            if (!$priceType instanceof PriceType) {
                $errors[$field.'priceType'] = 'invoice.problem.price_type_invalid';
            }
            $lines[] = new LineInput(
                $row['text'],
                $this->quantity($row['quantity'], $field, $errors),
                $row['unit'],
                $this->price($row['price'], $currency, $field, $errors),
                $priceType ?? PriceType::WithoutVat,
                $treatment,
                $kind,
                '' === $row['supplyCode'] ? null : $row['supplyCode'],
                $this->discount($row['discount'], $field, $errors),
            );
        }

        return new InvoiceLinesSubmission($lines, $rows, $errors);
    }

    /**
     * @return array{TaxTreatment, ?VatRateKind}|null
     */
    private function vat(string $choice): ?array
    {
        return match ($choice) {
            self::VAT_BASE => [TaxTreatment::Standard, VatRateKind::Base],
            self::VAT_REDUCED => [TaxTreatment::Standard, VatRateKind::Reduced],
            self::VAT_REVERSE_CHARGE => [TaxTreatment::ReverseCharge, null],
            self::VAT_EXEMPT => [TaxTreatment::Exempt, null],
            self::VAT_NONE => [TaxTreatment::NotApplicable, null],
            default => null,
        };
    }

    /**
     * @param array<string, string> $errors
     */
    private function price(string $typed, Currency $currency, string $field, array &$errors): Money
    {
        $decimal = $this->decimal($typed);
        if ('' === $decimal) {
            return Money::ofMinor(0, $currency);
        }
        if (1 !== preg_match(self::PRICE, $decimal)) {
            $errors[$field.'price'] = 'invoice.problem.price_invalid';

            return Money::ofMinor(0, $currency);
        }

        return Money::of($decimal, $currency);
    }

    /**
     * @param array<string, string> $errors
     */
    private function quantity(string $typed, string $field, array &$errors): Quantity
    {
        $decimal = $this->decimal($typed);
        if ('' === $decimal) {
            return Quantity::fromString('1');
        }
        if (1 !== preg_match(self::QUANTITY, $decimal)) {
            $errors[$field.'quantity'] = 'invoice.problem.quantity_invalid';

            return Quantity::fromString('1');
        }

        return Quantity::fromString($decimal);
    }

    /**
     * @param array<string, string> $errors
     */
    private function discount(string $typed, string $field, array &$errors): Percent
    {
        $decimal = $this->decimal($typed);
        if ('' === $decimal) {
            return Percent::fromString('0');
        }
        try {
            if (1 === preg_match(self::DISCOUNT, $decimal)) {
                return Percent::fromString($decimal);
            }
        } catch (\InvalidArgumentException) {
            // Over 100: the same mistake as a word.
        }
        $errors[$field.'discount'] = 'invoice.problem.discount_invalid';

        return Percent::fromString('0');
    }

    private function decimal(string $typed): string
    {
        return str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], $typed);
    }

    /**
     * @param array<string, string> $customer
     * @param array<string, string> $errors
     */
    private function checkCopy(array $customer, array &$errors): void
    {
        if ('' !== $customer['countryCode'] && !Countries::supports($customer['countryCode'])) {
            $errors['customer.countryCode'] = 'invoice.problem.country_invalid';
        }
        if ('' !== $customer['email'] && false === filter_var($customer['email'], \FILTER_VALIDATE_EMAIL)) {
            $errors['customer.email'] = 'invoice.problem.email_invalid';
        }
    }

    private function seriesId(string $id): ?string
    {
        return '' === $id ? null : $this->id($id);
    }

    private function id(string $id): string
    {
        return Uuid::isValid($id) ? $id : self::NO_ID;
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array<string, string>
     */
    private function customer(array $post): array
    {
        $sent = $post['customer'] ?? [];
        $customer = [];
        foreach (self::CUSTOMER_FIELDS as $field) {
            $value = \is_array($sent) ? ($sent[$field] ?? '') : '';
            $customer[$field] = \is_string($value) ? trim($value) : '';
        }

        return $customer;
    }

    /**
     * @param array<string, string> $customer
     */
    private function copy(array $customer): DocumentParty
    {
        return new DocumentParty(
            $customer['name'],
            '' === $customer['registrationNumber'] ? null : $customer['registrationNumber'],
            '' === $customer['vatId'] ? null : $customer['vatId'],
            new Address($customer['street'], $customer['city'], $customer['postalCode'], $customer['countryCode']),
            $customer['registerEntry'],
            '' === $customer['email'] ? null : $customer['email'],
            $customer['phone'],
            $customer['web'],
            null,
            null,
        );
    }

    /**
     * @param array<string, mixed> $post
     */
    private function text(array $post, string $field): string
    {
        $value = $post[$field] ?? '';

        return \is_string($value) ? $value : '';
    }
}
