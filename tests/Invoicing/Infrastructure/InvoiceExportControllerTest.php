<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Contract\DocumentKind;
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

final class InvoiceExportControllerTest extends WebTestCase
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

    public function testAnEmptyMonthSaysSoAndGivesNoFile(): void
    {
        $this->signedIn();
        $before = $this->temporary();

        $crawler = $this->post(['month' => '2026-10']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('No documents', $crawler->filter('main form [role="status"]')->text());
        self::assertSame($before, $this->temporary());
    }

    public function testAMonthMustBeOne(): void
    {
        $this->signedIn();

        foreach (['', '2026-13', '2026-1', 'soon', '2026-10-01'] as $month) {
            $this->post(['month' => $month]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('#export-month-error', 'valid month');
        }
    }

    public function testAGuestGets403(): void
    {
        $this->signedIn(Role::Guest);

        $this->client->request('GET', '/invoices/export');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/invoices/export');
        self::assertResponseStatusCodeSame(403);
    }

    public function testADownloadNeedsAToken(): void
    {
        $this->signedIn();

        $this->client->request('POST', '/invoices/export', ['_token' => 'wrong', 'month' => '2026-10']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $values
     */
    private function post(array $values): \Symfony\Component\DomCrawler\Crawler
    {
        $form = $this->client->request('GET', '/invoices/export');
        $token = (string) $form->filter('form[action="/invoices/export"] input[name="_token"]')->attr('value');

        return $this->client->request('POST', '/invoices/export', $values + ['_token' => $token]);
    }

    /**
     * @return list<string>
     */
    private function temporary(): array
    {
        $files = glob(sys_get_temp_dir().'/foresi-bundle-*');

        return false === $files ? [] : $files;
    }

    private function signedIn(Role $role = Role::Owner): void
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
        $this->em()->flush();
        $this->browser->signIn();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
