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
use Foresi\Party\Domain\LegalEntity;
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

final class RecurringListControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private LegalEntity $firm;
    private Series $series;

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

    public function testAnEmptyListOffersANewRecurringInvoice(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices/recurring');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Recurring invoices');
        self::assertCount(0, $crawler->filter('nav[aria-label="Recurring invoice lists"]'));
        self::assertGreaterThan(0, $crawler->filter('main a[href="/invoices/recurring/new"]')->count());
        self::assertStringContainsString('Recurring invoice', $crawler->filter('main')->text(), 'the hint names the menu item on an invoice');
    }

    public function testTheSidebarAndThePaletteOfferRecurringInvoices(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices');

        $link = $crawler->filter('#sidebar a[href="/invoices/recurring"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('Recurring invoices', $link->text());
        self::assertStringContainsString('/invoices/recurring/new', (string) $this->client->getResponse()->getContent(), 'the palette offers a new one');
    }

    public function testTheDetailNeedsAKnownTemplate(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/recurring/019a0000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
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
        $this->em()->flush();
        $this->browser->signIn();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
