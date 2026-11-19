<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Invoicing\Application\CorrectionInput;
use Foresi\Invoicing\Application\CustomerOption;
use Foresi\Invoicing\Application\CustomerPicker;
use Foresi\Invoicing\Application\EditInvoice;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoicingAccess;
use Foresi\Invoicing\Application\InvoicingNotAllowed;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\MarkDelivered;
use Foresi\Invoicing\Application\NewInvoiceDefaults;
use Foresi\Invoicing\Application\PaymentRefused;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\InvalidDeletionReason;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Invoicing\Domain\VariableSymbol;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The writes of sales invoices over the API and MCP. It reads the text of the
 * inputs into an InvoiceInput, fills what is left out as the invoice form does
 * (NewInvoiceDefaults for a new invoice, the stored invoice for a change), and
 * calls the use cases of the form: IssueInvoice, EditInvoice, TrashInvoice and MarkDelivered.
 * Every rule, the number of the series included, stays theirs; what IssueRules
 * refuses leaves here as IssueRefused, for the caller to word. Each returns the
 * id of the invoice to answer with.
 */
final readonly class InvoiceWrites
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';
    private const string QUANTITY = '/^-?\d{1,9}(?:\.\d{1,4})?$/D';
    private const string DISCOUNT = '/^\d{1,3}(?:\.\d{1,2})?$/D';
    private const array LINE_KEYS = ['text', 'unit_price', 'quantity', 'unit', 'price_type', 'vat', 'discount', 'supply_code'];
    private const array VAT = ['base', 'reduced', 'second_reduced', 'reverse_charge', 'exempt', 'none'];

    public function __construct(
        private InvoicingAccess $access,
        private IssueInvoice $issue,
        private EditInvoice $edit,
        private TrashInvoice $trash,
        private MarkDelivered $sent,
        private InvoiceStore $store,
        private NewInvoiceDefaults $defaults,
        private CustomerPicker $customers,
        private PartyQuery $parties,
        private InstanceFirm $instance,
    ) {
    }

    /**
     * @throws IssueRefused     when a rule refuses; nothing is stored and no number is consumed
     * @throws InvoicingRefused
     */
    public function create(CreateInvoiceInput $input): string
    {
        $this->allowed();
        $firm = $this->firm();
        $kind = DocumentKind::from($input->kind);
        $issueDate = $this->date($input->issueDate, 'issueDate');
        try {
            $draft = $this->defaults->for($firm, $issueDate, $kind);
        } catch (UnsupportedCountry $unsupported) {
            throw InvoicingRefused::conflict('firm_country_unsupported', 'The country of the firm has no invoicing rules in Foresi.', $unsupported);
        }
        $customer = $this->customerOf($input->customerId);
        if ($customer instanceof CustomerOption) {
            $draft = $this->defaults->withCustomer($draft, $customer);
        }
        $currency = null === $input->currency ? $draft->currency : $this->currency($input->currency);
        $lines = $this->lines($input->lines, $currency, VatRegime::of($firm->vatStatus()), $customer?->defaults->reverseCharge ?? false);

        return $this->issue->issue(new InvoiceInput(
            $firm->id(),
            $this->id($input->seriesId, 'seriesId'),
            $this->typed($input->number),
            $this->id($input->customerId, 'customerId') ?? '',
            null,
            $draft->issueDate,
            $this->date($input->taxPointDate, 'taxPointDate') ?? $draft->issueDate,
            $this->date($input->dueDate, 'dueDate') ?? $draft->dueDate,
            null === $input->paymentMethod ? $draft->paymentMethod : PaymentMethod::from($input->paymentMethod),
            $input->bankAccount ?? $draft->bankAccount,
            $input->variableSymbol ?? '',
            $input->constantSymbol ?? $draft->constantSymbol,
            $input->orderNumber,
            $input->description ?? ($lines[0]->text ?? ''),
            $input->textBefore ?? $draft->textBefore,
            $input->textAfter ?? $draft->textAfter,
            null === $input->language ? $draft->language : DocumentLanguage::from($input->language),
            $lines,
            null === $input->variableSymbol,
            $kind,
        ))->id();
    }

    /**
     * @throws IssueRefused     when a rule refuses, the locks of the number included; nothing changes
     * @throws InvoicingRefused
     */
    public function update(UpdateInvoiceInput $input): string
    {
        $this->allowed();
        // A tax document on payment is refused by IssueRules, as on the screen.
        $invoice = $this->invoice($input->invoiceId);
        $firm = $invoice->firm();
        $customerId = null === $input->customerId ? $invoice->customerParty()->id() : ($this->id($input->customerId, 'customerId') ?? '');
        $reverseCharge = $this->customerOf($customerId)?->defaults->reverseCharge ?? false;
        $lines = null === $input->lines
            ? array_map($this->storedLine(...), $this->store->lines($invoice))
            : $this->lines($input->lines, $invoice->currency(), $invoice->vatRegime(), $reverseCharge);
        // A symbol that followed the number keeps following it when the number changes.
        $follows = null === $input->variableSymbol && $invoice->variableSymbol() === VariableSymbol::fromNumber($invoice->number());

        $this->edit->edit($invoice->id(), new InvoiceInput(
            $firm->id(),
            $this->id($input->seriesId, 'seriesId'),
            $this->typed($input->number),
            $customerId,
            null,
            $this->date($input->issueDate, 'issueDate') ?? $invoice->issueDate(),
            $this->date($input->taxPointDate, 'taxPointDate') ?? $invoice->taxPointDate() ?? $invoice->issueDate(),
            $this->date($input->dueDate, 'dueDate') ?? $invoice->dueDate(),
            null === $input->paymentMethod ? $invoice->paymentMethod() : PaymentMethod::from($input->paymentMethod),
            $input->bankAccount ?? $invoice->bankAccount(),
            $input->variableSymbol ?? $invoice->variableSymbol(),
            $input->constantSymbol ?? $invoice->constantSymbol(),
            $input->orderNumber ?? $invoice->orderNumber(),
            $input->description ?? $invoice->description(),
            $input->textBefore ?? $invoice->textBefore(),
            $input->textAfter ?? $invoice->textAfter(),
            null === $input->language ? $invoice->language() : DocumentLanguage::from($input->language),
            $lines,
            $follows,
            $invoice->kind(),
            $invoice->taxOnReceipt(),
            $this->correction($invoice),
        ), $invoice->version());

        return $invoice->id();
    }

    /**
     * @throws InvoicingRefused
     */
    public function trash(TrashInvoiceInput $input): string
    {
        $this->allowed();
        $id = $this->invoice($input->invoiceId)->id();
        try {
            $this->trash->trash($id, $input->reason);
        } catch (InvalidDeletionReason) {
            throw InvoicingRefused::unreadable(InvoicingRefused::REASON, 'reason', \sprintf('A reason of 1 to %d characters is needed.', Invoice::MAX_REASON_LENGTH));
        } catch (PaymentRefused $refused) {
            throw InvoicingRefused::conflict(InvoicingRefused::HAS_PAYMENTS, 'The document has payments; remove them in Foresi before it goes to the trash.', $refused);
        } catch (UnknownInvoice $unknown) {
            throw InvoicingRefused::notFound($unknown);
        } catch (\DomainException $refused) {
            throw InvoicingRefused::conflict(InvoicingRefused::NOT_TRASHABLE, 'A sent document, or one in the trash already, cannot go to the trash. Correct a sent one with a correction document in Foresi.', $refused);
        }

        return $id;
    }

    /**
     * @throws IssueRefused     when what it deducted or billed was taken meanwhile
     * @throws InvoicingRefused
     */
    public function restore(RestoreInvoiceInput $input): string
    {
        $this->allowed();
        $id = $this->invoice($input->invoiceId)->id();
        try {
            $this->trash->restore($id);
        } catch (PaymentRefused $refused) {
            throw InvoicingRefused::conflict(InvoicingRefused::CANCELLED, 'The document taxes a payment that was cancelled or replaced, so it stays in the trash.', $refused);
        }

        return $id;
    }

    /**
     * Marks it sent by hand through MarkDelivered, as the row menu does: once only, so a
     * repeated call answers the document as it is.
     *
     * @throws InvoicingRefused
     */
    public function markSent(MarkInvoiceSentInput $input): string
    {
        $this->allowed();
        $id = $this->invoice($input->invoiceId)->id();
        try {
            $this->sent->mark($id);
        } catch (UnknownInvoice $unknown) {
            throw InvoicingRefused::notFound($unknown);
        } catch (\DomainException $refused) {
            throw InvoicingRefused::conflict(InvoicingRefused::TRASHED, 'The document is in the trash; restore it with foresi_restore_invoice before it is marked sent.', $refused);
        }

        return $id;
    }

    private function allowed(): void
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (InvoicingNotAllowed $denied) {
            throw InvoicingRefused::forbidden($denied);
        }
    }

    private function invoice(string $id): Invoice
    {
        try {
            return $this->store->get($this->id($id, 'invoiceId') ?? '');
        } catch (UnknownInvoice $unknown) {
            throw InvoicingRefused::notFound($unknown);
        }
    }

    private function firm(): LegalEntity
    {
        try {
            $firm = $this->instance->get();
        } catch (NoFirmYet $unknown) {
            throw InvoicingRefused::unknownFirm($unknown);
        }

        return $firm;
    }

    /**
     * The contact as the form offers it to the firm; null for an unknown or
     * archived one, which IssueRules then refuses by name.
     */
    private function customerOf(string $id): ?CustomerOption
    {
        $id = $this->id($id, 'customerId');
        $party = null === $id ? null : $this->parties->find($id);

        return $party instanceof Party ? $this->customers->find($party->id()) : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<LineInput>
     */
    private function lines(array $rows, Currency $currency, VatRegime $regime, bool $reverseCharge): array
    {
        $lines = [];
        foreach ($rows as $index => $row) {
            $lines[] = $this->line($row, $index, $currency, $regime, $reverseCharge);
        }

        return $lines;
    }

    /**
     * @param array<array-key, mixed> $row a JSON object, whose keys may be digits
     */
    private function line(array $row, int $index, Currency $currency, VatRegime $regime, bool $reverseCharge): LineInput
    {
        $field = static fn (string $name): string => \sprintf('lines[%d].%s', $index, $name);
        foreach (array_keys($row) as $key) {
            if (!\in_array($key, self::LINE_KEYS, true)) {
                throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field((string) $key), \sprintf('A line takes only %s.', implode(', ', self::LINE_KEYS)));
            }
        }
        $text = static function (string $name) use ($row, $field): ?string {
            $value = $row[$name] ?? null;
            if (null !== $value && !\is_string($value)) {
                throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field($name), 'Every field of a line is a string, numbers included, such as "1500.00".');
            }

            return null === $value ? null : trim($value);
        };
        $price = $text('unit_price') ?? throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field('unit_price'), 'Every line needs a unit_price, such as "1500.00".');
        if (1 !== preg_match('/^-?\d{1,13}(\.\d{1,'.$currency->minorUnits().'})?$/D', $price)) {
            throw InvoicingRefused::unreadable(InvoicingRefused::NUMBER, $field('unit_price'), \sprintf('The price must be a decimal with at most %d places, such as "1500.00".', $currency->minorUnits()));
        }
        $quantity = $text('quantity') ?? '1';
        if (1 !== preg_match(self::QUANTITY, $quantity)) {
            throw InvoicingRefused::unreadable(InvoicingRefused::NUMBER, $field('quantity'), 'The quantity must be a decimal with at most 4 places, such as "2" or "1.5".');
        }
        $discount = $text('discount') ?? '0';
        try {
            $percent = 1 === preg_match(self::DISCOUNT, $discount) ? Percent::fromString($discount) : null;
        } catch (\InvalidArgumentException) {
            $percent = null;
        }
        if (!$percent instanceof Percent) {
            throw InvoicingRefused::unreadable(InvoicingRefused::NUMBER, $field('discount'), 'The discount is a percentage from 0 to 100 with at most 2 places, such as "10".');
        }
        $priceType = PriceType::tryFrom($text('price_type') ?? PriceType::WithoutVat->value)
            ?? throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field('price_type'), 'The price type is without_vat, with_vat or base_only.');
        $vat = $text('vat') ?? $this->defaultVat($regime, $reverseCharge);
        if (!\in_array($vat, self::VAT, true)) {
            throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field('vat'), \sprintf('The VAT of a line is one of %s.', implode(', ', self::VAT)));
        }
        [$treatment, $rateKind] = $this->vat($vat);
        $supplyCode = $text('supply_code');

        return new LineInput(
            $text('text') ?? '',
            Quantity::fromString($quantity),
            $text('unit') ?? '',
            Money::of($price, $currency),
            $priceType,
            $treatment,
            $rateKind,
            '' === $supplyCode ? null : $supplyCode,
            $percent,
        );
    }

    /**
     * A new line of the form: the base rate for a VAT payer, reverse charge for
     * a customer who is taxed instead, and no VAT for a firm that is no payer.
     */
    private function defaultVat(VatRegime $regime, bool $reverseCharge): string
    {
        if (VatRegime::Payer !== $regime) {
            return 'none';
        }

        return $reverseCharge ? 'reverse_charge' : 'base';
    }

    /**
     * @return array{TaxTreatment, ?VatRateKind}
     */
    private function vat(string $choice): array
    {
        return match ($choice) {
            'reduced' => [TaxTreatment::Standard, VatRateKind::Reduced],
            'second_reduced' => [TaxTreatment::Standard, VatRateKind::SecondReduced],
            'reverse_charge' => [TaxTreatment::ReverseCharge, null],
            'exempt' => [TaxTreatment::Exempt, null],
            'none' => [TaxTreatment::NotApplicable, null],
            default => [TaxTreatment::Standard, VatRateKind::Base],
        };
    }

    /**
     * A stored line as it is, so an edit that leaves the lines out changes none:
     * it keeps the advance it deducts and names itself as the source, so the
     * work it billed stays with it.
     */
    private function storedLine(InvoiceLine $line): LineInput
    {
        return new LineInput($line->text(), $line->quantity(), $line->unit(), $line->price(), $line->priceType(), $line->treatment(), $line->rateKind(), $line->supplyCode(), $line->discount(), $line->deducts(), $line->id());
    }

    /**
     * What a correction document corrects stays as it was issued.
     */
    private function correction(Invoice $invoice): ?CorrectionInput
    {
        $corrected = $invoice->correctedInvoiceId();

        return null === $corrected ? null : new CorrectionInput($corrected, (string) $invoice->correctionReason(), $invoice->correctionType(), $invoice->decisiveDate());
    }

    private function typed(?string $number): ?string
    {
        $number = null === $number ? '' : trim($number);

        return '' === $number ? null : $number;
    }

    private function currency(string $code): Currency
    {
        return Currency::tryFrom($code)
            ?? throw InvoicingRefused::unreadable(InvoicingRefused::CURRENCY, 'currency', 'The currency is not an ISO 4217 code Foresi knows, such as CZK or EUR.');
    }

    private function id(?string $id, string $field): ?string
    {
        if (null === $id) {
            return null;
        }
        if (1 !== preg_match(self::UUID, $id)) {
            throw InvoicingRefused::unreadable('id_invalid', $field, 'The id must be a UUID, as the read tools return it.');
        }

        return strtolower($id);
    }

    private function date(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw InvoicingRefused::unreadable(InvoicingRefused::DATE, $field, 'The day must be YYYY-MM-DD.');
        }

        return $day;
    }
}
