<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\CustomerOption;
use App\Invoicing\Application\CustomerPicker;
use App\Invoicing\Application\InvoiceDraftValues;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\NewInvoiceDefaults;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\PartyQuery;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Shared\Domain\Money;

/**
 * The form as text: from the defaults, from a stored invoice, and after the actions
 * that change it without saving, which make it work without JavaScript. A row that
 * moves keeps what was typed in it.
 */
final readonly class InvoiceFormValues
{
    public function __construct(
        private NewInvoiceDefaults $defaults,
        private CustomerPicker $picker,
        private ContactSettingsForFirm $contacts,
        private PartyQuery $parties,
        private InvoiceStore $store,
        private AmountText $amounts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ofDraft(InvoiceDraftValues $draft): array
    {
        return [
            'firmId' => $draft->firmId,
            'firmShown' => $draft->firmId,
            'seriesId' => $draft->seriesId ?? '',
            'number' => $draft->number,
            'numberPeek' => $draft->number,
            'customerId' => '',
            'customerQuery' => '',
            'customerEdit' => '',
            'customer' => $this->emptyCustomer(),
            'orderNumber' => '',
            'description' => '',
            'issueDate' => $draft->issueDate->format('Y-m-d'),
            'taxPointDate' => $draft->taxPointDate->format('Y-m-d'),
            'dueDate' => $draft->dueDate->format('Y-m-d'),
            'paymentMethod' => $draft->paymentMethod->value,
            'bankAccount' => $draft->bankAccount ?? '',
            'variableSymbol' => $draft->variableSymbol,
            'constantSymbol' => $draft->constantSymbol,
            'language' => $draft->language->value,
            'textBefore' => $draft->textBefore,
            'textAfter' => $draft->textAfter,
            'lines' => array_map($this->lineOfDraft(...), $draft->lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ofInvoice(Invoice $invoice): array
    {
        return [
            'firmId' => $invoice->firm()->id(),
            'firmShown' => $invoice->firm()->id(),
            'seriesId' => $invoice->series()->id(),
            'number' => $invoice->number(),
            'numberPeek' => $invoice->number(),
            'customerId' => $invoice->customerParty()->id(),
            'customerQuery' => $invoice->customer()->name,
            'customerEdit' => '',
            'customer' => $this->customerOf($invoice->customer()),
            'orderNumber' => $invoice->orderNumber(),
            'description' => $invoice->description(),
            'issueDate' => $invoice->issueDate()->format('Y-m-d'),
            'taxPointDate' => $invoice->taxPointDate()->format('Y-m-d'),
            'dueDate' => $invoice->dueDate()->format('Y-m-d'),
            'paymentMethod' => $invoice->paymentMethod()->value,
            'bankAccount' => $invoice->bankAccount() ?? '',
            'variableSymbol' => $invoice->variableSymbol(),
            'constantSymbol' => $invoice->constantSymbol(),
            'language' => $invoice->language()->value,
            'textBefore' => $invoice->textBefore(),
            'textAfter' => $invoice->textAfter(),
            'lines' => array_map($this->lineOfInvoice(...), $this->store->lines($invoice)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function emptyLine(VatRegime $regime): array
    {
        return ['text' => '', 'price' => '', 'quantity' => '1', 'unit' => '', 'priceType' => PriceType::WithoutVat->value, 'vat' => VatRegime::Payer === $regime ? InvoiceFormReader::VAT_BASE : InvoiceFormReader::VAT_NONE, 'supplyCode' => '', 'discount' => ''];
    }

    /**
     * A row is added, moved or removed; the last row stays, emptied.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function withRowAction(array $values, string $action, VatRegime $regime): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = $values['lines'];
        [$name, $argument] = [...explode(':', $action, 2), ''];
        $index = (int) $argument;
        if ('add_line' === $name) {
            $rows[] = $this->emptyLine($regime);
        } elseif ('remove_line' === $name) {
            array_splice($rows, $index, 1);
        } elseif ('move_up' === $name && $index > 0) {
            [$rows[$index - 1], $rows[$index]] = [$rows[$index], $rows[$index - 1]];
        } elseif ('move_down' === $name && $index < \count($rows) - 1) {
            [$rows[$index + 1], $rows[$index]] = [$rows[$index], $rows[$index + 1]];
        }
        $values['lines'] = [] === $rows ? [$this->emptyLine($regime)] : $rows;

        return $values;
    }

    /**
     * The firm changed on a new document: what belongs to the firm follows it, what
     * was typed stays. A customer the new firm cannot invoice goes.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function forFirm(array $values, LegalEntity $firm): array
    {
        $draft = $this->defaults->for($firm);
        $own = $this->ofDraft($draft);
        foreach (['firmId', 'firmShown', 'seriesId', 'number', 'numberPeek', 'bankAccount', 'variableSymbol', 'constantSymbol', 'textBefore', 'textAfter'] as $field) {
            $values[$field] = $own[$field];
        }
        /** @var list<array<string, string>> $rows */
        $rows = $values['lines'];
        $values['lines'] = array_map(static fn (array $row): array => self::rowFor($row, $draft->regime), $rows);
        $party = $this->partyOf($values);
        if ($party instanceof Party && !$this->contacts->isCustomerOf($party, $firm)) {
            $values['customerId'] = '';
            $values['customerQuery'] = '';
            $values['customer'] = $this->emptyCustomer();
        }

        return $values;
    }

    /**
     * A customer was chosen: the same defaults the browser applies to the form.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function withCustomer(array $values, Party $party, LegalEntity $firm, \DateTimeImmutable $issueDate): array
    {
        $option = $this->picker->option($party, $firm);
        $draft = $this->defaults->withCustomer($this->defaults->for($firm)->onIssueDate($issueDate), $option);
        /** @var list<array<string, string>> $rows */
        $rows = $values['lines'];

        return array_merge($this->withCustomerShown($values, $option), [
            'dueDate' => $draft->dueDate->format('Y-m-d'),
            'paymentMethod' => $draft->paymentMethod->value,
            'language' => $draft->language->value,
            'lines' => VatRegime::Payer === $draft->regime ? array_map(static fn (array $row): array => self::rowAfterPick($row, $option), $rows) : $rows,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function withCustomerShown(array $values, CustomerOption $option): array
    {
        return array_merge($values, ['customerId' => $option->id, 'customerQuery' => $option->name, 'customer' => array_merge($this->emptyCustomer(), [
            'name' => $option->name,
            'registrationNumber' => $option->registrationNumber ?? '',
            'vatId' => $option->vatId ?? '',
            'street' => $option->billing->street,
            'city' => $option->billing->city,
            'postalCode' => $option->billing->postalCode,
            'countryCode' => $option->billing->countryCode,
            'email' => $option->email ?? '',
        ])]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function partyOf(array $values): ?Party
    {
        $id = $values['customerId'];

        return \is_string($id) && '' !== $id ? $this->parties->find($id) : null;
    }

    /**
     * @param array<string, string> $row
     *
     * @return array<string, string>
     */
    private static function rowFor(array $row, VatRegime $regime): array
    {
        if (VatRegime::NonPayer === $regime) {
            return array_merge($row, ['vat' => InvoiceFormReader::VAT_NONE, 'priceType' => PriceType::WithoutVat->value, 'supplyCode' => '']);
        }

        return InvoiceFormReader::VAT_NONE === $row['vat'] ? array_merge($row, ['vat' => InvoiceFormReader::VAT_BASE]) : $row;
    }

    /**
     * @param array<string, string> $row
     *
     * @return array<string, string>
     */
    private static function rowAfterPick(array $row, CustomerOption $option): array
    {
        if ($option->defaults->reverseCharge) {
            return array_merge($row, ['vat' => InvoiceFormReader::VAT_REVERSE_CHARGE]);
        }

        return InvoiceFormReader::VAT_REVERSE_CHARGE === $row['vat'] ? array_merge($row, ['vat' => InvoiceFormReader::VAT_BASE, 'supplyCode' => '']) : $row;
    }

    /**
     * @return array<string, string>
     */
    private function emptyCustomer(): array
    {
        return ['name' => '', 'registrationNumber' => '', 'vatId' => '', 'street' => '', 'city' => '', 'postalCode' => '', 'countryCode' => '', 'email' => '', 'phone' => '', 'web' => '', 'registerEntry' => ''];
    }

    /**
     * @return array<string, string>
     */
    private function customerOf(DocumentParty $party): array
    {
        return [
            'name' => $party->name,
            'registrationNumber' => $party->registrationNumber ?? '',
            'vatId' => $party->vatId ?? '',
            'street' => $party->address->street,
            'city' => $party->address->city,
            'postalCode' => $party->address->postalCode,
            'countryCode' => $party->address->countryCode,
            'email' => $party->email ?? '',
            'phone' => $party->phone,
            'web' => $party->web,
            'registerEntry' => $party->registerEntry,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function lineOfDraft(LineInput $line): array
    {
        return [
            'text' => $line->text,
            'price' => $this->price($line->price),
            'quantity' => $this->amounts->trimmed($line->quantity->toDecimal()),
            'unit' => $line->unit,
            'priceType' => $line->priceType->value,
            'vat' => $this->vatChoice($line->treatment, $line->rateKind),
            'supplyCode' => $line->supplyCode ?? '',
            'discount' => 0 === $line->discount->basisPoints() ? '' : $this->amounts->trimmed($line->discount->decimal()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function lineOfInvoice(InvoiceLine $line): array
    {
        return [
            'text' => $line->text(),
            'price' => $this->price($line->price()),
            'quantity' => $this->amounts->trimmed($line->quantity()->toDecimal()),
            'unit' => $line->unit(),
            'priceType' => $line->priceType()->value,
            'vat' => $this->vatChoice($line->treatment(), $line->rateKind()),
            'supplyCode' => $line->supplyCode() ?? '',
            'discount' => 0 === $line->discount()->basisPoints() ? '' : $this->amounts->trimmed($line->discount()->decimal()),
        ];
    }

    private function price(Money $price): string
    {
        return $price->isZero() ? '' : $this->amounts->plain($price);
    }

    private function vatChoice(TaxTreatment $treatment, ?VatRateKind $kind): string
    {
        return match ($treatment) {
            TaxTreatment::Standard => VatRateKind::Reduced === $kind ? InvoiceFormReader::VAT_REDUCED : InvoiceFormReader::VAT_BASE,
            TaxTreatment::ReverseCharge => InvoiceFormReader::VAT_REVERSE_CHARGE,
            TaxTreatment::Exempt => InvoiceFormReader::VAT_EXEMPT,
            TaxTreatment::NotApplicable => InvoiceFormReader::VAT_NONE,
        };
    }
}
