<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class FirmInvoicingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        Clock::set(new MockClock('2026-10-31 12:00:00'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAFormatWhoseNumbersWouldNotFitIsRefused(): void
    {
        $this->signedInFirm();
        $this->saveNewSeries(['name' => 'Long', 'format' => str_repeat('A', 31).'{NNNN}']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#format-error', '40');
    }

    private function signedInFirm(): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $this->firm();
    }

    private function firm(): void
    {
        $this->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->em()->flush();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function newSeriesForm(): array
    {
        $crawler = $this->client->request('GET', $this->settingsUrl().'/series/new');

        return $crawler->filter('main form')->form()->getPhpValues();
    }

    /**
     * @param array<string, string> $values
     */
    private function saveNewSeries(array $values): void
    {
        $this->client->request('POST', $this->settingsUrl().'/series/new', $values + $this->newSeriesForm());
    }

    private function settingsUrl(): string
    {
        return '/settings/firm/invoicing';
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
