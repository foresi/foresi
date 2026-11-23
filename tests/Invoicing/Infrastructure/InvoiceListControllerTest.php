<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

final class InvoiceListControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnEmptyListOffersANewInvoice(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Invoices');
        self::assertCount(0, $crawler->filter('nav[aria-label="Invoice lists"]'));
        $new = $crawler->filter('main a[href="/invoices/new"]');
        self::assertGreaterThan(0, $new->count());
        self::assertSame('false', $new->first()->attr('data-turbo'));
    }

    public function testTheSidebarLeadsToTheListAndThePaletteToANewInvoice(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices');

        self::assertCount(1, $crawler->filter('#sidebar a[href="/invoices"][aria-current="page"]'));
        $options = $crawler->filter('[data-controller="command-palette"] [role="option"]')->each(static fn (Crawler $option): string => $option->text());
        self::assertContains('Invoices', array_map(trim(...), $options));
        self::assertContains('New invoice', array_map(trim(...), $options));
    }

    public function testOnlyAWholeMonthIsAMonth(): void
    {
        self::assertSame('2026-01', InvoiceFilter::monthOf('2026-01'));
        self::assertSame('2026-12', InvoiceFilter::monthOf('2026-12'));
        foreach (['', '2026-13', '2026-00', '2026-1', '0999-05', '2026-05-01', ' 2026-05', '2026-05 ', 'x2026-05'] as $value) {
            self::assertNull(InvoiceFilter::monthOf($value), $value);
        }
    }

    private function signedIn(Role $role = Role::Owner, bool $server = false): void
    {
        $this->browser->seed(role: $role);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($this->firm);
        FirmAccounts::add($this->em(), self::IBAN);
        $this->series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->series->markDefault();
        $this->em()->persist($this->series);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($this->customer);
        $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        if ($server) {
            $this->em()->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', self::getContainer()->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        }
        $this->em()->flush();
        $this->browser->signIn();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
