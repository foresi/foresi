<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\EditInvoice;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A payer firm with a series and a domestic customer, on a fresh schema.
 */
abstract class InvoiceTestCase extends KernelTestCase
{
    protected const string IBAN = 'CZ6508000000192000145399';

    protected EntityManagerInterface $em;
    protected IssueInvoice $issue;
    protected EditInvoice $edit;
    protected TrashInvoice $trash;
    protected MarkDelivered $delivered;
    protected InvoiceStore $store;
    protected NumberCounter $counter;
    protected SeriesCatalog $catalog;
    protected LegalEntity $firm;
    protected Series $series;
    protected Party $customer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $container->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->issue = $container->get(IssueInvoice::class);
        $this->edit = $container->get(EditInvoice::class);
        $this->trash = $container->get(TrashInvoice::class);
        $this->delivered = $container->get(MarkDelivered::class);
        $this->store = $container->get(InvoiceStore::class);
        $this->counter = $container->get(NumberCounter::class);
        $this->catalog = $container->get(SeriesCatalog::class);

        $this->firm = $this->firm();
        $this->series = $this->seriesOf($this->firm);
        $this->customer = $this->customer();
        $this->em->flush();
    }

    protected function firm(VatStatus $status = VatStatus::Registered, ?string $vatId = 'CZ12345695', string $register = 'Example register, entry C 1'): LegalEntity
    {
        $firm = new LegalEntity(
            'Example Firm s.r.o.',
            new RegistrationNumber('CZ', '12345695'),
            null === $vatId ? null : VatId::fromString($vatId),
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
            $status,
            $register,
            [self::IBAN],
        );
        $this->em->persist($firm);

        return $firm;
    }

    protected function seriesOf(LegalEntity $firm): Series
    {
        $series = new Series($firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->em->persist($series);

        return $series;
    }

    protected function customer(?string $vatId = 'CZ87654320', ?Address $address = null): Party
    {
        $party = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), null === $vatId ? null : VatId::fromString($vatId), '+420 000 000 000');
        $this->em->persist($party);
        $this->em->persist(new PartyAddress($party, AddressType::Billing, $address ?? new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->persist(new PartyEmail($party, 'billing@example.com', EmailPurpose::Invoices));

        return $party;
    }

    /**
     * @param array<LineInput>|null $lines
     * @param array<string, mixed>  $overrides named arguments of InvoiceInput
     */
    protected function input(?array $lines = null, array $overrides = []): InvoiceInput
    {
        return new \ReflectionClass(InvoiceInput::class)->newInstanceArgs(array_merge([
            'firmId' => $this->firm->id(),
            'seriesId' => $this->series->id(),
            'typedNumber' => null,
            'customerId' => $this->customer->id(),
            'customerOnDocument' => null,
            'issueDate' => new \DateTimeImmutable('2026-11-01'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-01'),
            'dueDate' => new \DateTimeImmutable('2026-11-15'),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'variableSymbol' => '20260001',
            'constantSymbol' => '0308',
            'orderNumber' => 'PO-1',
            'description' => 'Consulting',
            'textBefore' => 'Before',
            'textAfter' => 'After',
            'language' => DocumentLanguage::Czech,
            'lines' => $lines ?? [$this->line()],
        ], $overrides));
    }

    protected function line(
        string $price = '1000.00',
        PriceType $type = PriceType::WithoutVat,
        TaxTreatment $treatment = TaxTreatment::Standard,
        ?VatRateKind $kind = VatRateKind::Base,
        ?string $supplyCode = null,
        string $text = 'Work',
    ): LineInput {
        return new LineInput($text, Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), $type, $treatment, $kind, $supplyCode, Percent::fromString('0'));
    }

    /**
     * An edit from a form opened just now.
     */
    protected function edited(Invoice $invoice, InvoiceInput $input): void
    {
        $this->em->clear();
        $this->edit->edit($invoice->id(), $input, $this->store->get($invoice->id())->version());
    }

    protected function reloaded(Invoice $invoice): Invoice
    {
        $id = $invoice->id();
        $this->em->clear();

        return $this->store->get($id);
    }

    /**
     * @return list<string> the keys of the problems of a refused save
     */
    protected function refusalOf(callable $save): array
    {
        try {
            $save();
        } catch (IssueRefused $refused) {
            return array_map(static fn (\App\Invoicing\Application\IssueProblem $problem): string => $problem->key, $refused->problems);
        }
        self::fail('Expected the save to be refused.');
    }

    protected function assertRefusedFor(string $key, callable $save): void
    {
        self::assertContains($key, $this->refusalOf($save));
    }
}
