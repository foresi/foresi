<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Application\SendRefused;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @phpstan-type Dialog array{_token: string, _submission: string, return: string, to?: list<string>, cc?: list<string>, others: string, copyToMe?: string, subject: string, body: string}
 */
final class InvoiceSendControllerTest extends WebTestCase
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

    /**
     * A refusal that names no field shows its reason as "invoice.send.error.{reason}".
     */
    public function testEveryRefusalHasATranslationInBothLanguages(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $reasons = array_values(array_filter(new \ReflectionClass(SendRefused::class)->getConstants(), \is_string(...)));
        self::assertNotEmpty($reasons);
        foreach (['cs', 'en'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            foreach ($reasons as $reason) {
                self::assertTrue($catalogue->defines('invoice.send.error.'.$reason, 'messages+intl-icu'), $locale.': '.$reason);
            }
        }
    }

    public function testStaffOnly(): void
    {
        $this->signedIn(Role::Guest);
        $id = '019a0000-0000-7000-8000-000000000001';

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, '/invoices/'.$id.'/send');
            self::assertResponseStatusCodeSame(403, $method);
        }
    }

    public function testRemindIsStaffOnly(): void
    {
        $this->signedIn(Role::Guest);
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, '/invoices/019a0000-0000-7000-8000-000000000001/remind');
            self::assertResponseStatusCodeSame(403, $method);
        }
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en', bool $server = true, EmailPurpose $email = EmailPurpose::Invoices): void
    {
        $this->browser->seed($locale, $role);
        $this->scoped(function () use ($server, $email): void {
            [$this->series, $this->customer] = $this->firmWithCustomer($server, $email);
        });
        $this->browser->signIn();
    }

    /**
     * A payer firm with a contact address and, if asked, a mail server, and a customer
     * with one address.
     *
     * @return array{Series, Party}
     */
    private function firmWithCustomer(bool $server, EmailPurpose $purpose): array
    {
        $em = $this->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1'));
        FirmAccounts::add($em, self::IBAN);
        $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(new PartyEmail($customer, 'billing@example.com', $purpose));
        if ($server) {
            $em->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', self::getContainer()->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        }
        $em->flush();
        $settings = self::getContainer()->get(FirmInvoicingSettings::class);
        $own = $settings->get();
        $own->change($own->rounding(), '', '', '', 'office@example.com', '', '');
        $settings->save($own);
        $em->flush();

        return [$series, $customer];
    }

    /**
     * Runs outside a request.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(callable $work): mixed
    {
        return $work();
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }
}
