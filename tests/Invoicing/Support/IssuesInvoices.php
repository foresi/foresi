<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Support;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentAllocation;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Invoices and payments for a test of Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase:
 * issued as the form would issue them, in a series that is made on first use.
 */
trait IssuesInvoices
{
    private ?string $defaultSeriesId = null;

    abstract protected function em(): EntityManagerInterface;

    /**
     * A standard series of invoices, stored.
     *
     * @return string the id of the series
     */
    protected function series(): string
    {
        $em = $this->em();
        $series = new Series('Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $em->persist($series);
        $em->flush();

        return $series->id();
    }

    /**
     * Another customer of the organisation, stored.
     *
     * @return string the id of the customer
     */
    protected function otherCustomer(): string
    {
        $em = $this->em();
        $customer = new Party(PartyKind::Company, 'Other Customer a.s.', new RegistrationNumber('CZ', '25596641'), VatId::fromString('CZ25596641'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 3', 'Customer City', '200 00', 'CZ')));
        $em->flush();

        return $customer->id();
    }

    /**
     * The series that invoices go to unless told otherwise.
     */
    protected function seriesId(): string
    {
        return $this->defaultSeriesId ??= $this->series();
    }

    /**
     * An invoice of the active organisation, issued as the form would issue it.
     *
     * @param array<string, mixed> $overrides named arguments of InvoiceInput
     */
    protected function invoice(string $issueDate = '2026-11-01', string $due = '2026-11-15', string $price = '1000.00', array $overrides = [], ?string $seriesId = null, ?string $customerId = null): Invoice
    {
        $input = new \ReflectionClass(InvoiceInput::class)->newInstanceArgs(array_merge([
            'seriesId' => $seriesId ?? $this->seriesId(),
            'typedNumber' => null,
            'customerId' => $customerId ?? $this->customerId,
            'customerOnDocument' => null,
            'issueDate' => new \DateTimeImmutable($issueDate),
            'taxPointDate' => new \DateTimeImmutable($issueDate),
            'dueDate' => new \DateTimeImmutable($due),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'variableSymbol' => '20260001',
            'constantSymbol' => '0308',
            'orderNumber' => 'PO-1',
            'description' => 'Consulting',
            'textBefore' => 'Before',
            'textAfter' => 'After',
            'language' => DocumentLanguage::Czech,
            'lines' => [new LineInput('Work', Quantity::fromString('2'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))],
        ], $overrides));
        $invoice = self::getContainer()->get(IssueInvoice::class)->issue($input);
        $this->em()->flush();

        return $invoice;
    }

    /**
     * A payment of the active organisation with its allocations, as a recorded one would be.
     *
     * @param array<string, string> $parts the amount in whole currency units by invoice id; the payment is their sum
     */
    protected function payment(
        string $on = '2026-11-10',
        array $parts = [],
        PaymentDirection $direction = PaymentDirection::Incoming,
        string $note = '',
        ?string $amount = null,
        bool $cancelled = false,
    ): Payment {
        $em = $this->em();
        $total = $amount ?? (string) array_sum(array_map(intval(...), $parts));
        $payment = new Payment(new \DateTimeImmutable($on), Money::of($total, Currency::CZK), PaymentMethod::BankTransfer, PaymentSource::Manual, null, $note, null, new \DateTimeImmutable('2026-11-10 12:00:00'), $direction);
        $em->persist($payment);
        foreach ($parts as $invoiceId => $part) {
            $em->persist(new PaymentAllocation($payment, $em->getReference(Invoice::class, $invoiceId) ?? throw new \LogicException('No invoice.'), Money::of($part, Currency::CZK)));
        }
        if ($cancelled) {
            $payment->cancel(new \DateTimeImmutable('2026-11-11 08:00:00'), $this->user->id());
        }
        $em->flush();

        return $payment;
    }
}
