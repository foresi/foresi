<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Country;

use Doctrine\DBAL\Connection;
use Foresi\Banking\Application\ProcessMessage;
use Foresi\Banking\Application\ReceiveInboundMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Banking\Support\Scalars;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The bank tab of a firm: accounts added and edited in dialogs, the inbound
 * address of each account, and the pairing switches. Every write is read back
 * from the database.
 */
final class FirmBankControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string OTHER_IBAN = 'CZ5508000000001234567899';

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    /** @var array<string, string> environment variables a test changed, with their old values */
    private array $changedEnv = [];

    protected function setUp(): void
    {
        $this->boot();
        Clock::set(new MockClock('2026-11-02 12:00:00'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        foreach ($this->changedEnv as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        parent::tearDown();
    }

    public function testAddingAnAccountInTheDialogStoresIt(): void
    {
        $this->signedInFirm();
        $form = $this->dialogForm($this->url().'/accounts/new');
        self::assertSame('1', $form['primary'] ?? null, 'The first account is offered as the primary one.');

        $this->dialogPost($this->url().'/accounts/new', ['number' => '19-2000145399/0800', 'currency' => 'EUR', 'label' => 'Operating', 'primary' => '1'] + $form);

        self::assertResponseIsSuccessful();
        self::assertSame(['location' => $this->url()], json_decode(Scalars::text($this->client->getResponse()->getContent()), true));
        self::assertSame([['iban' => self::IBAN, 'currency' => 'EUR', 'label' => 'Operating', 'primary_flag' => 1]], $this->storedAccounts());
        $crawler = $this->client->request('GET', $this->url());
        self::assertStringContainsString('The account Operating is added.', $crawler->filter('main')->text());
        self::assertStringContainsString('CZ65 0800 0000 1920 0014 5399', $crawler->filter('main table')->text());
    }

    public function testEditingCannotChangeTheIban(): void
    {
        $this->signedInFirm();
        $account = $this->withAccount();
        $url = $this->url().'/accounts/'.$account.'/edit';
        $crawler = $this->client->request('GET', $url, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertCount(0, $crawler->filter('input[name="number"]'));
        self::assertStringContainsString('19-2000145399/0800', $crawler->filter('#number')->text());

        $this->dialogPost($url, ['number' => self::OTHER_IBAN, 'currency' => 'USD', 'label' => 'Renamed'] + $crawler->filter('#bank-account-form')->form()->getPhpValues());

        self::assertResponseIsSuccessful();
        self::assertSame([['iban' => self::IBAN, 'currency' => 'USD', 'label' => 'Renamed', 'primary_flag' => 1]], $this->storedAccounts());
    }

    /**
     * Setting up trust means reading a raw header: the tab shows the owner whose
     * results the last message carried on top, and what Foresi made of them,
     * and nothing else of the mail.
     */
    public function testThePairingTabShowsWhoVouchedForTheLastMessage(): void
    {
        $this->signedInFirm();
        $account = $this->withAccount();
        $this->generate($account);
        $address = $this->shownAddress($account);
        self::assertCount(0, $this->client->request('GET', $this->url('pairing'))->filter('[data-trust-check]'), 'Nothing came yet.');
        $raw = (string) file_get_contents(__DIR__.'/../../../Fixtures/Banking/kb/incoming-booked.eml');
        $raw = "Authentication-Results: mx.example.com; dkim=pass header.d=kb.cz; dmarc=pass header.from=kb.cz\n".str_replace('bank+token@example.com', $address, $raw);

        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveInboundMail::class)->receive($raw, InboundChannel::Webhook));
        $id = Scalars::text($this->connection()->fetchOne('SELECT id FROM inbound_message'));
        $before = $this->client->request('GET', $this->url('pairing'))->filter('[data-bank-feed="'.$account.'"] [data-trust-check]')->text();
        self::getContainer()->get(ProcessMessage::class)->process($id);

        $check = $this->client->request('GET', $this->url('pairing'))->filter('[data-bank-feed="'.$account.'"] [data-trust-check]');

        self::assertStringContainsString('Waiting to be processed', $before);
        self::assertCount(1, $check);
        $text = $check->text();
        self::assertStringContainsString('webhook', $text);
        self::assertStringContainsString('mx.example.com', $text);
        self::assertStringContainsString('a trusted server', $text);
        self::assertStringContainsString('Verified sender', $text);
        self::assertStringNotContainsString('Oznámení', $text, 'Nothing else of the mail.');
        self::assertStringNotContainsString('kb.cz', $text);
    }

    private function boot(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    private function signedInFirm(): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $this->firm();
    }

    private function firm(): void
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em = $this->browser->em();
        $em->persist($firm);
        $em->flush();
    }

    /**
     * @return string the id of the new account
     */
    private function withAccount(string $iban = self::IBAN): string
    {
        $em = $this->browser->em();
        $account = FirmAccounts::add($em, $iban);
        $em->flush();

        return $account->id();
    }

    private function url(?string $tab = null): string
    {
        return '/settings/firm/bank'.(null === $tab ? '' : '?tab='.$tab);
    }

    /**
     * @return array<mixed>
     */
    private function dialogForm(string $url): array
    {
        $crawler = $this->client->request('GET', $url, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#dialog-page'));

        return $crawler->filter('#bank-account-form')->form()->getPhpValues();
    }

    /**
     * @param array<mixed> $values
     */
    private function dialogPost(string $url, array $values): Crawler
    {
        return $this->client->request('POST', $url, $values, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1', 'HTTP_ACCEPT' => 'application/json, text/html']);
    }

    private function generate(string $account): void
    {
        $this->client->request('POST', $this->url().'/accounts/'.$account.'/address', ['_token' => $this->addressToken()]);
    }

    private function shownAddress(string $account): string
    {
        $crawler = $this->client->request('GET', $this->url('pairing'));
        $field = $crawler->filter('#feed-'.$account.'-address');
        self::assertCount(1, $field, 'The tab shows the address.');
        self::assertCount(1, $crawler->filter('[data-bank-feed="'.$account.'"] [data-action="copy#copy"]'), 'Next to a copy button.');

        return Scalars::text($field->attr('value'));
    }

    private function addressToken(): string
    {
        $crawler = $this->client->request('GET', $this->url('pairing'));
        $token = $crawler->filter('[data-bank-feed] form input[name="_token"]');

        return $token->count() > 0 ? Scalars::text($token->first()->attr('value')) : 'none';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedAccounts(): array
    {
        return array_map(static fn (array $row): array => ['iban' => $row['iban'], 'currency' => $row['currency'], 'label' => $row['label'], 'primary_flag' => null === $row['primary_flag'] ? null : Scalars::int($row['primary_flag'])], $this->connection()->fetchAllAssociative('SELECT iban, currency, label, primary_flag FROM firm_bank_account ORDER BY position'));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
