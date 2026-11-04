<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\IssueRecurringInvoices;
use App\Invoicing\Application\IssueRecurringOccurrence;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxPointRule;
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
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The shell notices of recurring invoices, on every page for staff: templates
 * that failed, invoices waiting for review, and a day whose run has not finished
 * by 07:00 in the tenant's zone (Prague, from the firm's country) while templates
 * are due.
 */
final class RecurringNoticesTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        [$this->tenant] = $this->browser->seed(['Tenant A'], 'en', Role::Owner);
        $this->scoped(function (): void {
            $em = $this->browser->em();
            $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
            $em->persist($this->firm);
            FirmAccounts::add($em, $this->firm, self::IBAN);
            $this->series = new Series($this->firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $this->series->markDefault();
            $em->persist($this->series);
            $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
            $em->persist($this->customer);
            $em->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $em->persist(new PartyEmail($this->customer, 'billing@example.com', EmailPurpose::Invoices));
            $em->flush();
        });
        $this->browser->signIn();
    }

    public function testNoNoticeWithoutRecurringWork(): void
    {
        $this->template();

        $this->client->request('GET', '/contacts');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testFailingTemplatesRaiseAnError(): void
    {
        $template = $this->template();
        $this->connection()->executeStatement("UPDATE recurring_invoice SET last_failure_code = 'invoice.problem.customer_archived', last_failure_at = '2026-11-03 05:00:00' WHERE id = ?", [$template->id()]);

        $crawler = $this->client->request('GET', '/contacts');

        self::assertSelectorTextContains('[data-shell-notices]', 'A recurring invoice could not be issued or sent.');
        self::assertSame('alert', $crawler->filter('[data-shell-notices] [role]')->first()->attr('role'), 'an error');
    }

    public function testAnEndedTemplateNoLongerRaisesItsFailure(): void
    {
        $template = $this->template();
        $this->connection()->executeStatement("UPDATE recurring_invoice SET last_failure_code = 'send.no_server', last_failure_at = '2026-11-03 05:00:00', state = 'ended', next_occurrence = NULL WHERE id = ?", [$template->id()]);

        $this->client->request('GET', '/contacts');

        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testReviewRaisesAWarningWithTheToSendFilter(): void
    {
        $template = $this->template('2026-11-03', RecurringDelivery::Review);
        $this->scoped(static fn () => self::getContainer()->get(IssueRecurringOccurrence::class)->issue($template->id(), new \DateTimeImmutable('2026-11-03')));
        $this->finishedToday();

        $crawler = $this->client->request('GET', '/contacts');

        self::assertSelectorTextContains('[data-shell-notices]', 'An invoice from a recurring template is waiting for you to send it.');
        self::assertSame('/invoices?toSend=1', $crawler->filter('[data-shell-notices] a')->attr('href'));
        self::assertSame('status', $crawler->filter('[data-shell-notices] [role]')->first()->attr('role'), 'a warning');
    }

    public function testAnUnfinishedRunAfterSevenRaisesAnError(): void
    {
        $this->template('2026-11-03');

        // 07:01 in Prague, the day's run has not finished and a template is due.
        self::mockTime('2026-11-03 06:01:00');
        $this->client->request('GET', '/contacts');

        self::assertSelectorTextContains('[data-shell-notices]', 'Today\'s recurring invoices have not been issued yet, although it is past 7:00.');
        self::assertSelectorExists('[data-shell-notices] [role="alert"]');

        $this->finishedToday();
        $this->client->request('GET', '/contacts');
        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testNoNoticeBeforeSeven(): void
    {
        $this->template('2026-11-03');

        // 06:59 in Prague.
        self::mockTime('2026-11-03 05:59:00');
        $this->client->request('GET', '/contacts');

        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testAFinishedRunWithNothingDueRaisesNothingLater(): void
    {
        $this->template('2026-11-04');

        self::mockTime('2026-11-03 08:00:00');
        $this->client->request('GET', '/contacts');

        self::assertSelectorNotExists('[data-shell-notices]');
    }

    private function template(string $firstIssueOn = '2026-12-01', RecurringDelivery $delivery = RecurringDelivery::Send): RecurringInvoice
    {
        return $this->scoped(fn (): RecurringInvoice => self::getContainer()->get(SaveRecurringInvoice::class)->create(new RecurringInput(
            $this->firm->id(),
            $this->series->id(),
            $this->customer->id(),
            PaymentMethod::BankTransfer,
            self::IBAN,
            '',
            '',
            'Hosting {month/year}',
            '',
            '',
            DocumentLanguage::Czech,
            [new LineInput('Hosting', Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))],
            new Recurrence(new \DateTimeImmutable($firstIssueOn), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null),
            TaxPointRule::IssueDate,
            14,
            $delivery,
        )));
    }

    /**
     * The run of today, 3 November, as finished.
     */
    private function finishedToday(): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO scheduled_job_run (id, tenant_id, job, last_period, finished_at) VALUES (?, ?, ?, ?, ?)',
            ['019a0000-0000-7000-8000-0000000000aa', $this->tenant->toString(), IssueRecurringInvoices::NAME, '2026-11-03', '2026-11-03 04:10:00'],
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(callable $work): mixed
    {
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
