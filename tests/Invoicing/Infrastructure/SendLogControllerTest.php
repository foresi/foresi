<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\MailEncryption;
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
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The send log, the sends on the history of an invoice, their kept PDFs, the
 * warning of the form and the notices of the shell.
 */
final class SendLogControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        RecordingMailGateway::reset();
    }

    protected function tearDown(): void
    {
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testTheLogIsForStaffAndInTheSidebar(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices/sent');

        $current = $crawler->filter('nav a[aria-current="page"]');
        self::assertSame(['Sent e-mails'], $current->each(static fn (Crawler $link): string => trim($link->text())));

        $this->client->request('GET', '/invoices');
        self::assertSame(['Invoices'], $this->client->getCrawler()->filter('nav a[aria-current="page"]')->each(static fn (Crawler $link): string => trim($link->text())));
    }

    public function testAGuestGets403(): void
    {
        $this->signedIn(Role::Guest);

        $this->client->request('GET', '/invoices/sent');

        self::assertResponseStatusCodeSame(403);
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en'): void
    {
        $this->browser->seed($locale, $role);
        $this->series = $this->firmWithServer('Example Firm s.r.o.', '12345695');
        $this->customer = $this->newCustomer('Example Customer a.s.');
        $this->browser->signIn();
    }

    private function firmWithServer(string $name, string $registrationNumber): Series
    {
        $em = $this->em();
        $em->persist(new LegalEntity($name, new RegistrationNumber('CZ', $registrationNumber), VatId::fromString('CZ'.$registrationNumber), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1'));
        FirmAccounts::add($em, self::IBAN);
        $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $em->persist($series);
        $em->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', self::getContainer()->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', $name));
        $em->flush();

        return $series;
    }

    private function newCustomer(string $name): Party
    {
        $em = $this->em();
        $customer = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(new PartyEmail($customer, 'billing@example.com', EmailPurpose::Invoices));
        $em->flush();

        return $customer;
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }
}
