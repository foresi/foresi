<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Application\GenerateAddress;
use App\Banking\Application\InboundAddresses;
use App\Banking\Application\InboundToken;
use App\Banking\Application\ProcessMessage;
use App\Banking\Application\ReceiveInboundMail;
use App\Banking\Application\ReceiveOutcome;
use App\Banking\Domain\InboundChannel;
use App\Banking\Infrastructure\Doctrine\DoctrineBankFeedDirectory;
use App\Identity\Domain\Role;
use App\Invoicing\Application\MailSecrets;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Doctrine\TenantFilterBypass;
use App\Tests\Banking\Support\Scalars;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Party\Support\FirmAccounts;
use App\Ui\Dialog\DialogRequest;
use Doctrine\DBAL\Connection;
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
    private const string ADDRESS = '/^bank\+[a-z2-7]{26}@example\.com$/D';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
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

    public function testTheFirmHasABankTabWithBothSections(): void
    {
        $firm = $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->url($firm));

        self::assertResponseIsSuccessful();
        self::assertSame('page', $crawler->filter('main nav[aria-label="Firm sections"] a[href$="/bank"]')->attr('aria-current'));
        self::assertCount(2, $crawler->filter('main nav[aria-label="Bank settings sections"] a'));
        self::assertSelectorTextContains('main', 'No accounts yet');
        self::assertCount(1, $crawler->filter('#bank-account-add[data-dialog][aria-haspopup="dialog"]'));
    }

    public function testTheFirmFormLinksToTheBankTab(): void
    {
        $firm = $this->signedInFirm();

        $crawler = $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');

        self::assertSame($this->url($firm), $crawler->filter('#bank-accounts-note a')->attr('href'));
    }

    public function testAddingAnAccountInTheDialogStoresIt(): void
    {
        $firm = $this->signedInFirm();
        $form = $this->dialogForm($this->url($firm).'/accounts/new');
        self::assertSame('1', $form['primary'] ?? null, 'The first account is offered as the primary one.');

        $this->dialogPost($this->url($firm).'/accounts/new', ['number' => '19-2000145399/0800', 'currency' => 'EUR', 'label' => 'Operating', 'primary' => '1'] + $form);

        self::assertResponseIsSuccessful();
        self::assertSame(['location' => $this->url($firm)], json_decode(Scalars::text($this->client->getResponse()->getContent()), true));
        self::assertSame([['iban' => self::IBAN, 'currency' => 'EUR', 'label' => 'Operating', 'primary_flag' => 1, 'legal_entity_id' => $firm->id()]], $this->storedAccounts());
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertStringContainsString('The account Operating is added.', $crawler->filter('main')->text());
        self::assertStringContainsString('CZ65 0800 0000 1920 0014 5399', $crawler->filter('main table')->text());
    }

    public function testTheAccountFormIsAPageToo(): void
    {
        $firm = $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->url($firm).'/accounts/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main #bank-account-form');
        self::assertSelectorExists('main [data-action-bar] button[form="bank-account-form"]');
        self::assertCount(0, $crawler->filter('dialog#dialog-page'));
    }

    public function testABadIbanAnswers422InTheDialog(): void
    {
        $firm = $this->signedInFirm();
        $form = $this->dialogForm($this->url($firm).'/accounts/new');

        $crawler = $this->dialogPost($this->url($firm).'/accounts/new', ['number' => 'CZ00 1234', 'currency' => 'CZK', 'label' => ''] + $form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('dialog#dialog-page'));
        self::assertStringContainsString('Enter an IBAN or a domestic account number', $crawler->filter('#number-error')->text());
        self::assertSame('CZ00 1234', $crawler->filter('#number')->attr('value'));
        self::assertSame([], $this->storedAccounts());
    }

    public function testTheSameIbanTwiceIsRefusedOnTheField(): void
    {
        $firm = $this->signedInFirm();
        $this->withAccount($firm);
        $form = $this->dialogForm($this->url($firm).'/accounts/new');

        $crawler = $this->dialogPost($this->url($firm).'/accounts/new', ['number' => self::IBAN, 'currency' => 'CZK'] + $form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already has this account', $crawler->filter('#number-error')->text());
        self::assertCount(1, $this->storedAccounts());
    }

    public function testEditingCannotChangeTheIban(): void
    {
        $firm = $this->signedInFirm();
        $account = $this->withAccount($firm);
        $url = $this->url($firm).'/accounts/'.$account.'/edit';
        $crawler = $this->client->request('GET', $url, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertCount(0, $crawler->filter('input[name="number"]'));
        self::assertStringContainsString('19-2000145399/0800', $crawler->filter('#number')->text());

        $this->dialogPost($url, ['number' => self::OTHER_IBAN, 'currency' => 'USD', 'label' => 'Renamed'] + $crawler->filter('#bank-account-form')->form()->getPhpValues());

        self::assertResponseIsSuccessful();
        self::assertSame([['iban' => self::IBAN, 'currency' => 'USD', 'label' => 'Renamed', 'primary_flag' => 1, 'legal_entity_id' => $firm->id()]], $this->storedAccounts());
    }

    public function testMakingPrimaryArchivingAndRestoring(): void
    {
        $firm = $this->signedInFirm();
        $first = $this->withAccount($firm);
        $second = $this->withAccount($firm, self::OTHER_IBAN);
        $token = $this->actionToken($firm);

        $this->client->request('POST', $this->url($firm).'/accounts/'.$second.'/primary', ['_token' => $token]);
        self::assertResponseRedirects($this->url($firm));
        self::assertSame([$second], $this->connection()->fetchFirstColumn('SELECT id FROM firm_bank_account WHERE primary_flag IS NOT NULL'));

        $this->client->request('POST', $this->url($firm).'/accounts/'.$second.'/archive', ['_token' => $token]);
        self::assertResponseRedirects($this->url($firm));
        self::assertNotNull($this->connection()->fetchOne('SELECT archived_at FROM firm_bank_account WHERE id = ?', [$second]));
        self::assertSame([$first], $this->connection()->fetchFirstColumn('SELECT id FROM firm_bank_account WHERE primary_flag IS NOT NULL'));

        $this->client->request('POST', $this->url($firm).'/accounts/'.$second.'/restore', ['_token' => $token]);
        self::assertNull($this->connection()->fetchOne('SELECT archived_at FROM firm_bank_account WHERE id = ?', [$second]));

        $this->client->request('POST', $this->url($firm).'/accounts/'.$second.'/archive', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->connection()->fetchOne('SELECT archived_at FROM firm_bank_account WHERE id = ?', [$second]));
    }

    public function testGeneratingStoresOnlyHashAndSealedToken(): void
    {
        $firm = $this->signedInFirm();
        $account = $this->withAccount($firm);

        $this->generate($firm, $account);

        self::assertResponseRedirects($this->url($firm, 'pairing'));
        $address = $this->shownAddress($firm, $account);
        $token = Scalars::text($this->addresses()->tokenIn($address));
        $row = $this->connection()->fetchAssociative('SELECT * FROM bank_feed');
        self::assertIsArray($row);
        self::assertSame(InboundToken::hash($token), $row['token_hash']);
        self::assertSame($token, self::getContainer()->get(MailSecrets::class)->open(Scalars::text($row['token_sealed'])));
        self::assertStringNotContainsString($token, Scalars::text(json_encode($row)));
        self::assertStringNotContainsString($token, Scalars::text(json_encode($this->connection()->fetchAllAssociative('SELECT * FROM audit_entry'))));
    }

    public function testTheShownAddressMatchesTheTemplateAndResolves(): void
    {
        $firm = $this->signedInFirm();
        $account = $this->withAccount($firm);

        $this->generate($firm, $account);
        $address = $this->shownAddress($firm, $account);

        self::assertMatchesRegularExpression(self::ADDRESS, $address);
        $route = $this->directory()->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($address))));
        self::assertNotNull($route);
        self::assertSame([$account, $firm->id()], [$route->accountId, $route->firmId]);
        // The dialog of the account shows the same address with the setup steps.
        $crawler = $this->client->request('GET', $this->url($firm).'/accounts/'.$account.'/address', server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertSame($address, $crawler->filter('dialog#dialog-page #address')->attr('value'));
        self::assertStringContainsString('MojeBanka Business', $crawler->filter('dialog#dialog-page')->text());
        self::assertStringContainsString('KB+', $crawler->filter('dialog#dialog-page')->text());
        self::assertStringEndsWith('docs/bank-pairing.md', Scalars::text($crawler->filter('dialog#dialog-page a[target="_blank"]')->attr('href')));
    }

    public function testPagesShowingTheAddressAreNeverStored(): void
    {
        $firm = $this->signedInFirm();
        $account = $this->withAccount($firm);
        $this->generate($firm, $account);
        $dialog = ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1'];

        foreach ([[$this->url($firm, 'pairing'), []], [$this->url($firm).'/accounts/'.$account.'/address', []], [$this->url($firm).'/accounts/'.$account.'/address', $dialog]] as [$url, $server]) {
            $this->client->request('GET', $url, server: $server);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-store', Scalars::text($this->client->getResponse()->headers->get('Cache-Control')), $url);
        }
    }

    /**
     * Setting up trust means reading a raw header: the tab shows the owner whose
     * results the last message carried on top, and what Foresi made of them,
     * and nothing else of the mail.
     */
    public function testThePairingTabShowsWhoVouchedForTheLastMessage(): void
    {
        $firm = $this->signedInFirm();
        $account = $this->withAccount($firm);
        $this->generate($firm, $account);
        $address = $this->shownAddress($firm, $account);
        self::assertCount(0, $this->client->request('GET', $this->url($firm, 'pairing'))->filter('[data-trust-check]'), 'Nothing came yet.');
        $raw = (string) file_get_contents(__DIR__.'/../../Fixtures/Banking/kb/incoming-booked.eml');
        $raw = "Authentication-Results: mx.example.com; dkim=pass header.d=kb.cz; dmarc=pass header.from=kb.cz\n".str_replace('bank+token@example.com', $address, $raw);

        $this->context->switchTo($firm->tenantId());
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveInboundMail::class)->receive($raw, InboundChannel::Webhook));
        $this->context->switchTo($firm->tenantId());
        $id = Scalars::text($this->connection()->fetchOne('SELECT id FROM inbound_message'));
        $before = $this->client->request('GET', $this->url($firm, 'pairing'))->filter('[data-bank-feed="'.$account.'"] [data-trust-check]')->text();
        $this->context->switchTo($firm->tenantId());
        self::getContainer()->get(ProcessMessage::class)->process($id);
        $this->context->reset();

        $check = $this->client->request('GET', $this->url($firm, 'pairing'))->filter('[data-bank-feed="'.$account.'"] [data-trust-check]');

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

    public function testGeneratingFromTheDialogClosesItOverTheTab(): void
    {
        $firm = $this->signedInFirm();
        $account = $this->withAccount($firm);
        $url = $this->url($firm).'/accounts/'.$account.'/address';
        $crawler = $this->client->request('GET', $url, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);

        $this->dialogPost($url, $crawler->filter('#bank-address-form')->form()->getPhpValues());

        self::assertSame(['location' => $this->url($firm, 'pairing')], json_decode(Scalars::text($this->client->getResponse()->getContent()), true));
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    public function testRegeneratingKillsTheOldToken(): void
    {
        $firm = $this->signedInFirm();
        $account = $this->withAccount($firm);
        $this->generate($firm, $account);
        $old = $this->shownAddress($firm, $account);

        $crawler = $this->client->request('GET', $this->url($firm, 'pairing'));
        $regenerate = $crawler->filter('[data-bank-feed="'.$account.'"] form[data-turbo-confirm]');
        self::assertCount(1, $regenerate, 'Regenerating asks first.');
        self::assertSame('danger', $regenerate->attr('data-confirm-tone'));
        $this->generate($firm, $account);
        $new = $this->shownAddress($firm, $account);

        self::assertNotSame($old, $new);
        self::assertNull($this->directory()->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($old)))));
        self::assertNotNull($this->directory()->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($new)))));
        self::assertSame(2, Scalars::int($this->connection()->fetchOne('SELECT generation FROM bank_feed')));
    }

    public function testPairingSwitchesAreStoredPerFirm(): void
    {
        $firm = $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url($firm, 'pairing'));
        $form = $crawler->filter('#bank-pairing')->form();
        $values = $form->getPhpValues();
        unset($values['autoPairing']);

        $this->client->request('POST', $form->getUri(), ['smallDifference' => '1'] + $values);

        self::assertResponseRedirects($this->url($firm, 'pairing'));
        $row = $this->connection()->fetchAssociative('SELECT legal_entity_id, auto_pairing, small_difference FROM firm_bank_pairing');
        self::assertIsArray($row);
        self::assertSame([$firm->id(), 0, 1], [$row['legal_entity_id'], Scalars::int($row['auto_pairing']), Scalars::int($row['small_difference'])]);
        $crawler = $this->client->request('GET', $this->url($firm, 'pairing'));
        self::assertNull($crawler->filter('#autoPairing')->attr('checked'));
        self::assertNotNull($crawler->filter('#smallDifference')->attr('checked'));
    }

    public function testDefaultsAreOnAndOffWithoutARow(): void
    {
        $firm = $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->url($firm, 'pairing'));

        self::assertNotNull($crawler->filter('#autoPairing')->attr('checked'));
        self::assertNull($crawler->filter('#smallDifference')->attr('checked'));
        self::assertStringContainsString('Settles a difference up to 0.99 Kč onto the invoice total', $crawler->filter('#smallDifference-help')->text());
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM firm_bank_pairing')));
    }

    public function testWithoutAnAddressTemplateGeneratingIsRefused(): void
    {
        $this->changeEnv('BANK_INBOX_ADDRESS', '');
        $firm = $this->signedInFirm();
        $account = $this->withAccount($firm);

        $crawler = $this->client->request('GET', $this->url($firm, 'pairing'));
        self::assertStringContainsString('Bank e-mail is not set up on this server', $crawler->filter('#bank-address-unavailable')->text());
        self::assertNotNull($crawler->filter('[data-bank-feed="'.$account.'"] button[type="submit"]')->attr('disabled'));
        $this->client->request('POST', $this->url($firm).'/accounts/'.$account.'/address', ['_token' => $this->addressToken($firm)]);

        self::assertResponseRedirects($this->url($firm, 'pairing'));
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    public function testAnotherTenantsFirmIs404(): void
    {
        $firm = $this->signedInFirm();
        $foreign = $this->firm($this->browser->fixtures->tenant('Foreign Tenant'));
        $foreignAccount = $this->withAccount($foreign);

        foreach (['', '?tab=pairing', '/accounts/new', '/accounts/'.$foreignAccount.'/edit', '/accounts/'.$foreignAccount.'/address'] as $path) {
            $this->client->request('GET', $this->url($foreign).$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        foreach (['/pairing', '/accounts/'.$foreignAccount.'/archive', '/accounts/'.$foreignAccount.'/address'] as $path) {
            $this->client->request('POST', $this->url($foreign).$path, ['_token' => 'any']);
            self::assertResponseStatusCodeSame(404, $path);
        }
        // Another tenant's account under one's own firm is not found either.
        $this->client->request('GET', $this->url($firm).'/accounts/'.$foreignAccount.'/edit');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    public function testAClientRoleGets403(): void
    {
        $tenant = $this->browser->seed(['Tenant A'], role: Role::Client)[0];
        $firm = $this->firm($tenant);
        $account = $this->withAccount($firm);
        $this->browser->signIn();

        $this->client->request('GET', $this->url($firm));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url($firm).'/accounts/'.$account.'/address');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url($firm).'/pairing');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    /**
     * Staff who are not the owner work with the firm but do not set up its bank:
     * the address takes in payment mail, so who knows it can feed the books.
     */
    public function testAMemberGets403AndNeverSeesTheAddress(): void
    {
        $tenant = $this->browser->seed(['Tenant A'], role: Role::Member)[0];
        $firm = $this->firm($tenant);
        $account = $this->withAccount($firm);
        $other = $this->withAccount($firm, self::OTHER_IBAN);
        $this->context->switchTo($tenant);
        $address = self::getContainer()->get(GenerateAddress::class)->generate($account);
        $this->context->reset();
        $token = Scalars::text($this->addresses()->tokenIn($address));
        $this->browser->signIn();
        $dialog = ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1'];

        $bodies = [];
        foreach (['', '?tab=pairing', '/accounts/'.$account.'/address', '/accounts/'.$other.'/address'] as $path) {
            foreach ([[], $dialog] as $server) {
                $this->client->request('GET', $this->url($firm).$path, server: $server);
                self::assertResponseStatusCodeSame(403, 'GET '.$path);
                $bodies[] = Scalars::text($this->client->getResponse()->getContent());
            }
        }
        foreach (['/accounts/'.$account.'/address', '/accounts/'.$other.'/address', '/pairing', '/accounts/'.$account.'/archive'] as $path) {
            $this->client->request('POST', $this->url($firm).$path, ['_token' => 'any', 'autoPairing' => '0']);
            self::assertResponseStatusCodeSame(403, 'POST '.$path);
            $bodies[] = Scalars::text($this->client->getResponse()->getContent());
        }

        self::assertSame([[$account, 1]], array_map(static fn (array $row): array => [$row['firm_bank_account_id'], Scalars::int($row['generation'])], $this->connection()->fetchAllAssociative('SELECT firm_bank_account_id, generation FROM bank_feed')), 'no address is made or replaced');
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM firm_bank_pairing')), 'the switches stay as they were');
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM firm_bank_account WHERE archived_at IS NOT NULL')));
        foreach ($bodies as $body) {
            self::assertStringNotContainsStringIgnoringCase($token, $body);
        }
    }

    private function boot(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
    }

    private function changeEnv(string $name, string $value): void
    {
        $this->changedEnv[$name] ??= \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : '';
        $_SERVER[$name] = $_ENV[$name] = $value;
        self::ensureKernelShutdown();
        $this->boot();
    }

    private function signedInFirm(): LegalEntity
    {
        [$tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        return $this->firm($tenant);
    }

    private function firm(TenantId $tenant): LegalEntity
    {
        $this->context->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em = $this->browser->em();
        $em->persist($firm);
        $em->flush();
        $this->context->reset();

        return $firm;
    }

    /**
     * @return string the id of the new account
     */
    private function withAccount(LegalEntity $firm, string $iban = self::IBAN): string
    {
        $this->context->switchTo($firm->tenantId());
        $em = $this->browser->em();
        $managed = $em->find(LegalEntity::class, $firm->id()) ?? throw new \LogicException('The firm is gone.');
        $account = FirmAccounts::add($em, $managed, $iban);
        $em->flush();
        $this->context->reset();

        return $account->id();
    }

    private function url(LegalEntity $firm, ?string $tab = null): string
    {
        return '/settings/firms/'.$firm->id().'/bank'.(null === $tab ? '' : '?tab='.$tab);
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

    private function generate(LegalEntity $firm, string $account): void
    {
        $this->client->request('POST', $this->url($firm).'/accounts/'.$account.'/address', ['_token' => $this->addressToken($firm)]);
    }

    private function shownAddress(LegalEntity $firm, string $account): string
    {
        $crawler = $this->client->request('GET', $this->url($firm, 'pairing'));
        $field = $crawler->filter('#feed-'.$account.'-address');
        self::assertCount(1, $field, 'The tab shows the address.');
        self::assertCount(1, $crawler->filter('[data-bank-feed="'.$account.'"] [data-action="copy#copy"]'), 'Next to a copy button.');

        return Scalars::text($field->attr('value'));
    }

    private function actionToken(LegalEntity $firm): string
    {
        $crawler = $this->client->request('GET', $this->url($firm));

        return Scalars::text($crawler->filter('[data-row-actions] form input[name="_token"]')->first()->attr('value'));
    }

    private function addressToken(LegalEntity $firm): string
    {
        $crawler = $this->client->request('GET', $this->url($firm, 'pairing'));
        $token = $crawler->filter('[data-bank-feed] form input[name="_token"]');

        return $token->count() > 0 ? Scalars::text($token->first()->attr('value')) : 'none';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedAccounts(): array
    {
        return array_map(static fn (array $row): array => ['iban' => $row['iban'], 'currency' => $row['currency'], 'label' => $row['label'], 'primary_flag' => null === $row['primary_flag'] ? null : Scalars::int($row['primary_flag']), 'legal_entity_id' => $row['legal_entity_id']], $this->connection()->fetchAllAssociative('SELECT iban, currency, label, primary_flag, legal_entity_id FROM firm_bank_account ORDER BY position'));
    }

    private function addresses(): InboundAddresses
    {
        return new InboundAddresses('bank+{token}@example.com');
    }

    private function directory(): DoctrineBankFeedDirectory
    {
        return new DoctrineBankFeedDirectory($this->connection(), self::getContainer()->get(TenantFilterBypass::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
