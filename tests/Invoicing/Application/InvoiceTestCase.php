<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Invoicing\Application\AdvanceDeductions;
use Foresi\Invoicing\Application\CorrectionInput;
use Foresi\Invoicing\Application\EditInvoice;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\MarkDelivered;
use Foresi\Invoicing\Application\NumberCounter;
use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\CorrectionType;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Party\Support\FirmAccounts;
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

        $this->issue = $container->get(IssueInvoice::class);
        $this->edit = $container->get(EditInvoice::class);
        $this->trash = $container->get(TrashInvoice::class);
        $this->delivered = $container->get(MarkDelivered::class);
        $this->store = $container->get(InvoiceStore::class);
        $this->counter = $container->get(NumberCounter::class);
        $this->catalog = $container->get(SeriesCatalog::class);

        $this->firm = $this->firm();
        $this->series = $this->seriesOf();
        $this->customer = $this->customer();
        $this->em->flush();
    }

    protected function firm(VatStatus $status = VatStatus::Registered, ?string $vatId = 'CZ12345695', string $register = 'Example register, entry C 1', string $registrationNumber = '12345695'): LegalEntity
    {
        $firm = new LegalEntity(
            'Example Firm s.r.o.',
            new RegistrationNumber('CZ', $registrationNumber),
            null === $vatId ? null : VatId::fromString($vatId),
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
            $status,
            $register,
        );
        $this->em->persist($firm);
        FirmAccounts::add($this->em, self::IBAN);

        return $firm;
    }

    /**
     * Gives the one firm another standing, before anything is issued.
     */
    protected function reconfigureFirm(VatStatus $status, ?string $vatId, string $register = 'Example register, entry C 1', ?Address $address = null): LegalEntity
    {
        $firm = $this->em->find(LegalEntity::class, $this->firm->id());
        self::assertInstanceOf(LegalEntity::class, $firm);
        $firm->update($firm->name(), $firm->registrationNumber(), null === $vatId ? null : VatId::fromString($vatId), $address ?? $firm->address(), $status, $register);
        $this->em->flush();
        $this->firm = $firm;

        return $firm;
    }

    protected function seriesOf(): Series
    {
        $series = new Series('Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
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
        return new LineInput($text, Quantity::fromString('1'), 'h', Money::of($price, Currency::of('CZK')), $type, $treatment, $kind, $supplyCode, Percent::fromString('0'));
    }

    /**
     * A credit note (a negative difference) or a debit note (a positive one) of the
     * base rate, issued on 20 November 2026.
     */
    protected function correctionOf(Invoice $original, string $difference): Invoice
    {
        $negative = str_starts_with($difference, '-');
        $line = new LineInput('Work', Quantity::fromString($negative ? '-1' : '1'), 'h', Money::of(ltrim($difference, '-'), Currency::of('CZK')), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return $this->issue->issue($this->input([$line], [
            'seriesId' => null,
            'kind' => DocumentKind::CorrectionDocument,
            'issueDate' => new \DateTimeImmutable('2026-11-20'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-20'),
            'variableSymbol' => '',
            'correction' => new CorrectionInput($original->id(), 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20')),
        ]));
    }

    /**
     * An edit from a form opened just now.
     */
    protected function edited(Invoice $invoice, InvoiceInput $input): void
    {
        $this->em->clear();
        $this->edit->edit($invoice->id(), $input, $this->store->get($invoice->id())->version());
    }

    /**
     * The one the container wires into IssueInvoice, as phpstan's container inlines it.
     */
    protected function deductions(): AdvanceDeductions
    {
        $deductions = new \ReflectionProperty(IssueInvoice::class, 'deductions')->getValue($this->issue);
        self::assertInstanceOf(AdvanceDeductions::class, $deductions);

        return $deductions;
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
            return array_map(static fn (\Foresi\Invoicing\Application\IssueProblem $problem): string => $problem->key, $refused->problems);
        }
        self::fail('Expected the save to be refused.');
    }

    protected function assertRefusedFor(string $key, callable $save): void
    {
        self::assertContains($key, $this->refusalOf($save));
    }
}
