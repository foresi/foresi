<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Integration\Country;

use Foresi\ExchangeRate\Domain\RateSourceSetting;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Settings → Exchange rates of a Czech firm: the ČNB by default, the ECB once
 * a member chooses it in the dialog, and nothing of it for a guest.
 */
final class RateSourcePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testAMemberSeesTheDefaultAndChoosesTheEcb(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/settings/exchange-rates');
        self::assertResponseIsSuccessful();
        $source = $this->plain($crawler->filter('[data-exchange-rate-source]')->text());
        self::assertStringContainsString('In use The default of your country, nobody has chosen yet Czech National Bank', $source);
        self::assertStringContainsString('Default of your country Czech National Bank', $source);
        self::assertStringContainsString('European Central Bank', $this->plain($crawler->filter('[data-exchange-rate-sources]')->text()));
        self::assertSame('/settings/exchange-rates/source', $crawler->filter('[data-exchange-rate-change]')->attr('href'));
        self::assertCount(1, $crawler->filter('#sidebar a[href="/settings/exchange-rates"]'));

        $dialog = $this->client->request('GET', '/settings/exchange-rates/source', server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['cnb', 'ecb'], $dialog->filter('input[name="source"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertSame('cnb', $dialog->filter('input[name="source"][checked]')->attr('value'));
        $token = (string) $dialog->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/exchange-rates/source', ['_token' => $token, 'source' => 'acme']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This source is not installed.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/settings/exchange-rates/source', ['_token' => $token, 'source' => 'ecb']);
        self::assertResponseRedirects('/settings/exchange-rates');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The exchange rates now come from European Central Bank.', $this->plain($crawler->text()));
        self::assertStringContainsString('In use Chosen in these settings European Central Bank', $this->plain($crawler->filter('[data-exchange-rate-source]')->text()));
        $setting = $this->browser->em()->find(RateSourceSetting::class, RateSourceSetting::ID);
        self::assertSame('ecb', $setting?->source());
    }

    public function testAWrongTokenIsRefused(): void
    {
        $this->signedIn();

        $this->client->request('POST', '/settings/exchange-rates/source', ['_token' => 'wrong', 'source' => 'ecb']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAGuestIsRefusedAndSeesNoMenuItem(): void
    {
        $this->signedIn(Role::Guest);

        foreach (['/settings/exchange-rates', '/settings/exchange-rates/source'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        $crawler = $this->client->request('GET', '/account/two-factor');
        self::assertCount(0, $crawler->filter('#sidebar a[href="/settings/exchange-rates"]'));
    }

    private function signedIn(Role $role = Role::Member): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345678'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, ''));
        $em->flush();
    }

    /**
     * The text with every run of spaces, the no-break ones included, as one space.
     */
    private function plain(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{a0}]+/u', ' ', $text));
    }

    private function dialogHeader(): string
    {
        return 'HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER));
    }
}
