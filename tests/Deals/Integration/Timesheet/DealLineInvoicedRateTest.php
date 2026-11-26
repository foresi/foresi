<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Integration\Timesheet;

use Foresi\Deals\Application\Api\AddDealLineInput;
use Foresi\Deals\Application\Api\UpdateDealLineInput;
use Foresi\Invoicing\Application\BilledLine;
use Foresi\Invoicing\Application\BilledWork;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Deals\Support\SalesTestCase;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Timesheet\Application\ManageTime;
use Foresi\Timesheet\Application\ProjectTimeSettings;
use Foresi\Timesheet\Application\TimeCandidate;
use Foresi\Timesheet\Application\TimeInvoicing;
use Foresi\Timesheet\Application\TimeScope;
use Foresi\Timesheet\Domain\LineGrouping;
use Foresi\Timesheet\Domain\RateSource;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Timesheet\Domain\TimeRounding;
use Foresi\Work\Application\ManageProjects;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The rate of an hourly deal line reaches time only while it is not invoiced:
 * an invoice keeps the rate the time took, so a later change of the line, or
 * unlinking the project, never reaches billed time (review focus 6 of plan 35).
 */
final class DealLineInvoicedRateTest extends SalesTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    public function testAlreadyInvoicedTimeKeepsItsRateAfterTheLineChanges(): void
    {
        $container = self::getContainer();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);
        $this->em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1'));
        FirmAccounts::add($this->em, self::IBAN);
        $this->em->persist(new PartyAddress($this->client, AddressType::Billing, new Address('Client Street 2', 'Client City', '200 00', 'CZ')));
        $this->em->flush();
        $project = $container->get(ManageProjects::class)->create('DUD', $this->client->id());
        $time = $container->get(ManageTime::class);
        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($project->id(), Money::of('1000', Currency::CZK));
        $deal = $this->deal('DUD');
        $line = $this->lines()->add(new AddDealLineInput($deal->id(), name: 'DUD development', unitPrice: '1200.00', billingKind: 'time_based', projectId: $project->id()));
        $entry = $time->log($project->id(), null, new \DateTimeImmutable('2026-11-16'), 60, billable: true)->id();

        // The time is invoiced at the line's rate.
        $invoicing = $container->get(TimeInvoicing::class);
        $scope = new TimeScope($this->client->id(), [$project->id()], new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));
        $candidates = $invoicing->candidates($scope);
        self::assertSame([RateSource::DealLine], array_map(static fn (TimeCandidate $candidate): ?RateSource => $candidate->source, $candidates));
        $ids = array_map(static fn (TimeCandidate $candidate): string => $candidate->entry->id, $candidates);
        $reference = $invoicing->select($scope, $invoicing->lines($scope, $ids, LineGrouping::Project, TimeRounding::None), 'hod', 'j. n. Y');
        $draft = $container->get(BilledWork::class)->draft($reference) ?? throw new \LogicException('The time chosen waits.');
        $lines = array_map(static fn (BilledLine $billed): LineInput => new LineInput($billed->text, $billed->quantity, $billed->unit, $billed->price, PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'), source: $billed->source), $draft->lines);
        $invoice = $container->get(IssueInvoice::class)->issue(new InvoiceInput(null, null, $draft->customerId, null, new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2026-12-04'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Time', '', '', DocumentLanguage::Czech, $lines, true, billedWork: $draft->reference));
        $this->assertBilledAt('1200', $entry, $invoice->id());

        // The line's rate changes, then the project is unlinked: billed time stays as it was billed.
        $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), unitPrice: '1500.00'));
        $this->assertBilledAt('1200', $entry, $invoice->id());
        $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), projectId: null));
        $this->assertBilledAt('1200', $entry, $invoice->id());

        // The trash releases the time with the rate it was billed at, not the line's rate now.
        $container->get(TrashInvoice::class)->trash($invoice->id(), 'Issued by mistake');
        $released = $this->em->getConnection()->fetchAssociative('SELECT rate_minor, rate_currency FROM released_time WHERE time_entry_id = ?', [$entry]);
        self::assertIsArray($released);
        self::assertEquals(['rate_minor' => 120000, 'rate_currency' => 'CZK'], $released);
    }

    private function assertBilledAt(string $rate, string $entryId, string $invoiceId): void
    {
        $this->em->clear();
        $entry = $this->em->find(TimeEntry::class, $entryId) ?? throw new \LogicException('Entry.');
        self::assertSame($invoiceId, $entry->invoiceId());
        self::assertTrue(Money::of($rate, Currency::CZK)->equals($entry->invoicedRate() ?? Money::ofMinor(0, Currency::EUR)));
        $row = $this->em->getRepository(InvoiceLine::class)->findOneBy(['invoice' => $invoiceId]) ?? throw new \LogicException('Line.');
        self::assertTrue(Money::of($rate, Currency::CZK)->equals($row->price()));
    }
}
