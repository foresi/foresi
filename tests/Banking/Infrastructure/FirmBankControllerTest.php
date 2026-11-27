<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Infrastructure;

use Doctrine\DBAL\Connection;
use Foresi\Banking\Application\FeedRoute;
use Foresi\Banking\Application\GenerateAddress;
use Foresi\Banking\Application\View\FirmBankAccountLists;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineBankFeedDirectory;
use Foresi\Identity\Domain\Role;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Application\InboundToken;
use Foresi\Mail\Domain\InboundAddress;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\Sealer;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Scalars;
use Foresi\Tests\Ui\Support\ListPageAssertions;
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
 * The tests that need the Czech rules are in Integration/Country.
 */
final class FirmBankControllerTest extends WebTestCase
{
    use ListPageAssertions;

    private const string IBAN = 'CZ6508000000192000145399';
    private const string OTHER_IBAN = 'CZ5508000000001234567899';
    private const string ADDRESS = '/^bank\+[a-z2-7]{26}@example\.com$/D';

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

    public function testTheFirmHasABankTabWithBothSections(): void
    {
        $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSame('page', $crawler->filter('main nav[aria-label="Firm sections"] a[href$="/bank"]')->attr('aria-current'));
        self::assertCount(2, $crawler->filter('main nav[aria-label="Bank settings sections"] a'));
        self::assertSelectorTextContains('main', 'No accounts yet');
        self::assertCount(1, $crawler->filter('#bank-account-add[data-dialog][aria-haspopup="dialog"]'));
    }

    public function testTheAccountsAreAListWithViews(): void
    {
        $this->signedInFirm();
        $this->withAccount();
        $this->withAccount(self::OTHER_IBAN);
        self::assertListWithViews(self::getContainer()->get(FirmBankAccountLists::class), 'banking.firm_accounts', ['name', 'iban', 'archived'], ['all' => false]);
        $rows = static fn (Crawler $crawler): int => $crawler->filter('[data-bank-accounts] tbody tr[data-id]')->count();

        $crawler = $this->client->request('GET', $this->url());
        self::assertSame(['name', 'iban', 'currency', 'state', 'address'], $crawler->filter('[data-bank-accounts] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column')));
        self::assertListTable($crawler, '[data-bank-accounts]', ['widths' => '']);
        self::assertSame(2, $rows($crawler));

        self::assertSame(1, $rows($this->client->request('GET', $this->url().'?q=CZ55')));
        self::assertSame(1, $rows(self::applyBuilder($this->client, $this->url(), 'iban', 'CZ65')));
        // The pairing tab has no table of its own.
        self::assertCount(0, $this->client->request('GET', $this->url('pairing'))->filter('[data-bank-accounts]'));
    }

    public function testTheFirmFormLinksToTheBankTab(): void
    {
        $this->signedInFirm();

        $crawler = $this->client->request('GET', '/settings/firm');

        self::assertSelectorTextContains('#bank-accounts-note', 'The firm\'s bank accounts are kept in its bank settings.');
        self::assertSame($this->url(), $crawler->filter('#bank-accounts-note a')->attr('href'));
    }

    public function testTheAccountFormIsAPageToo(): void
    {
        $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->url().'/accounts/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main #bank-account-form');
        self::assertSelectorExists('main [data-action-bar] button[form="bank-account-form"]');
        self::assertCount(0, $crawler->filter('dialog#dialog-page'));
    }

    public function testABadIbanAnswers422InTheDialog(): void
    {
        $this->signedInFirm();
        $form = $this->dialogForm($this->url().'/accounts/new');

        $crawler = $this->dialogPost($this->url().'/accounts/new', ['number' => 'CZ00 1234', 'currency' => 'CZK', 'label' => ''] + $form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('dialog#dialog-page'));
        self::assertStringContainsString('Enter an IBAN or a domestic account number', $crawler->filter('#number-error')->text());
        self::assertSame('CZ00 1234', $crawler->filter('#number')->attr('value'));
        self::assertSame([], $this->storedAccounts());
    }

    public function testTheSameIbanTwiceIsRefusedOnTheField(): void
    {
        $this->signedInFirm();
        $this->withAccount();
        $form = $this->dialogForm($this->url().'/accounts/new');

        $crawler = $this->dialogPost($this->url().'/accounts/new', ['number' => self::IBAN, 'currency' => 'CZK'] + $form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already has this account', $crawler->filter('#number-error')->text());
        self::assertCount(1, $this->storedAccounts());
    }

    public function testMakingPrimaryArchivingAndRestoring(): void
    {
        $this->signedInFirm();
        $first = $this->withAccount();
        $second = $this->withAccount(self::OTHER_IBAN);
        $token = $this->actionToken();

        $this->client->request('POST', $this->url().'/accounts/'.$second.'/primary', ['_token' => $token]);
        self::assertResponseRedirects($this->url());
        self::assertSame([$second], $this->connection()->fetchFirstColumn('SELECT id FROM firm_bank_account WHERE primary_flag IS NOT NULL'));

        $this->client->request('POST', $this->url().'/accounts/'.$second.'/archive', ['_token' => $token]);
        self::assertResponseRedirects($this->url());
        self::assertNotNull($this->connection()->fetchOne('SELECT archived_at FROM firm_bank_account WHERE id = ?', [$second]));
        self::assertSame([$first], $this->connection()->fetchFirstColumn('SELECT id FROM firm_bank_account WHERE primary_flag IS NOT NULL'));

        $this->client->request('POST', $this->url().'/accounts/'.$second.'/restore', ['_token' => $token]);
        self::assertNull($this->connection()->fetchOne('SELECT archived_at FROM firm_bank_account WHERE id = ?', [$second]));

        $this->client->request('POST', $this->url().'/accounts/'.$second.'/archive', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->connection()->fetchOne('SELECT archived_at FROM firm_bank_account WHERE id = ?', [$second]));
    }

    public function testGeneratingStoresOnlyHashAndSealedToken(): void
    {
        $this->signedInFirm();
        $account = $this->withAccount();

        $this->generate($account);

        self::assertResponseRedirects($this->url('pairing'));
        $address = $this->shownAddress($account);
        $token = Scalars::text($this->addresses()->tokenIn($address));
        $row = $this->connection()->fetchAssociative('SELECT f.*, a.token_hash, a.token_sealed FROM bank_feed f JOIN mail_inbound_address a ON a.id = f.mail_inbound_address_id');
        self::assertIsArray($row);
        self::assertSame(InboundToken::hash($token), $row['token_hash']);
        self::assertSame($token, self::getContainer()->get(Sealer::class)->open(Scalars::text($row['token_sealed'])));
        self::assertStringNotContainsString($token, Scalars::text(json_encode($row)));
        self::assertStringNotContainsString($token, Scalars::text(json_encode($this->connection()->fetchAllAssociative('SELECT * FROM audit_entry'))));
    }

    public function testTheShownAddressMatchesTheTemplateAndResolves(): void
    {
        $this->signedInFirm();
        $account = $this->withAccount();

        $this->generate($account);
        $address = $this->shownAddress($account);

        self::assertMatchesRegularExpression(self::ADDRESS, $address);
        $route = $this->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($address))));
        self::assertNotNull($route);
        self::assertSame($account, $route->accountId);
        // The dialog of the account shows the same address with the setup steps.
        $crawler = $this->client->request('GET', $this->url().'/accounts/'.$account.'/address', server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertSame($address, $crawler->filter('dialog#dialog-page #address')->attr('value'));
        self::assertStringContainsString('MojeBanka Business', $crawler->filter('dialog#dialog-page')->text());
        self::assertStringContainsString('KB+', $crawler->filter('dialog#dialog-page')->text());
        self::assertStringEndsWith('docs/bank-pairing.md', Scalars::text($crawler->filter('dialog#dialog-page a[target="_blank"]')->attr('href')));
    }

    public function testPagesShowingTheAddressAreNeverStored(): void
    {
        $this->signedInFirm();
        $account = $this->withAccount();
        $this->generate($account);
        $dialog = ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1'];

        foreach ([[$this->url('pairing'), []], [$this->url().'/accounts/'.$account.'/address', []], [$this->url().'/accounts/'.$account.'/address', $dialog]] as [$url, $server]) {
            $this->client->request('GET', $url, server: $server);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-store', Scalars::text($this->client->getResponse()->headers->get('Cache-Control')), $url);
        }
    }

    public function testGeneratingFromTheDialogClosesItOverTheTab(): void
    {
        $this->signedInFirm();
        $account = $this->withAccount();
        $url = $this->url().'/accounts/'.$account.'/address';
        $crawler = $this->client->request('GET', $url, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);

        $this->dialogPost($url, $crawler->filter('#bank-address-form')->form()->getPhpValues());

        self::assertSame(['location' => $this->url('pairing')], json_decode(Scalars::text($this->client->getResponse()->getContent()), true));
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    public function testRegeneratingKillsTheOldToken(): void
    {
        $this->signedInFirm();
        $account = $this->withAccount();
        $this->generate($account);
        $old = $this->shownAddress($account);

        $crawler = $this->client->request('GET', $this->url('pairing'));
        $regenerate = $crawler->filter('[data-bank-feed="'.$account.'"] form[data-turbo-confirm]');
        self::assertCount(1, $regenerate, 'Regenerating asks first.');
        self::assertSame('danger', $regenerate->attr('data-confirm-tone'));
        $this->generate($account);
        $new = $this->shownAddress($account);

        self::assertNotSame($old, $new);
        self::assertNull($this->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($old)))));
        self::assertNotNull($this->route(InboundToken::hash(Scalars::text($this->addresses()->tokenIn($new)))));
        self::assertSame(2, Scalars::int($this->connection()->fetchOne('SELECT generation FROM bank_feed')));
    }

    public function testPairingSwitchesAreStored(): void
    {
        $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url('pairing'));
        $form = $crawler->filter('#bank-pairing')->form();
        $values = $form->getPhpValues();
        unset($values['autoPairing']);

        $this->client->request('POST', $form->getUri(), ['smallDifference' => '1'] + $values);

        self::assertResponseRedirects($this->url('pairing'));
        $row = $this->connection()->fetchAssociative('SELECT auto_pairing, small_difference FROM firm_bank_pairing');
        self::assertIsArray($row);
        self::assertSame([0, 1], [Scalars::int($row['auto_pairing']), Scalars::int($row['small_difference'])]);
        $crawler = $this->client->request('GET', $this->url('pairing'));
        self::assertNull($crawler->filter('#autoPairing')->attr('checked'));
        self::assertNotNull($crawler->filter('#smallDifference')->attr('checked'));
    }

    public function testDefaultsAreOnAndOffWithoutARow(): void
    {
        $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->url('pairing'));

        self::assertNotNull($crawler->filter('#autoPairing')->attr('checked'));
        self::assertNull($crawler->filter('#smallDifference')->attr('checked'));
        self::assertStringContainsString('Settles a difference up to 0.99 Kč onto the invoice total', $crawler->filter('#smallDifference-help')->text());
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM firm_bank_pairing')));
    }

    public function testWithoutAnAddressTemplateGeneratingIsRefused(): void
    {
        $this->changeEnv('MAIL_INBOUND_ADDRESS', '');
        $this->signedInFirm();
        $account = $this->withAccount();

        $crawler = $this->client->request('GET', $this->url('pairing'));
        self::assertStringContainsString('Bank e-mail is not set up on this server', $crawler->filter('#bank-address-unavailable')->text());
        self::assertNotNull($crawler->filter('[data-bank-feed="'.$account.'"] button[type="submit"]')->attr('disabled'));
        $this->client->request('POST', $this->url().'/accounts/'.$account.'/address', ['_token' => $this->addressToken()]);

        self::assertResponseRedirects($this->url('pairing'));
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    public function testAClientRoleGets403(): void
    {
        $this->browser->seed(role: Role::Guest);
        $this->firm();
        $account = $this->withAccount();
        $this->browser->signIn();

        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url().'/accounts/'.$account.'/address');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url().'/pairing');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_feed')));
    }

    /**
     * Staff who are not the owner work with the firm but do not set up its bank:
     * the address takes in payment mail, so who knows it can feed the books.
     */
    public function testAMemberGets403AndNeverSeesTheAddress(): void
    {
        $this->browser->seed(role: Role::Member);
        $this->firm();
        $account = $this->withAccount();
        $other = $this->withAccount(self::OTHER_IBAN);
        $address = self::getContainer()->get(GenerateAddress::class)->generate($account);
        $token = Scalars::text($this->addresses()->tokenIn($address));
        $this->browser->signIn();
        $dialog = ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1'];

        $bodies = [];
        foreach (['', '?tab=pairing', '/accounts/'.$account.'/address', '/accounts/'.$other.'/address'] as $path) {
            foreach ([[], $dialog] as $server) {
                $this->client->request('GET', $this->url().$path, server: $server);
                self::assertResponseStatusCodeSame(403, 'GET '.$path);
                $bodies[] = Scalars::text($this->client->getResponse()->getContent());
            }
        }
        foreach (['/accounts/'.$account.'/address', '/accounts/'.$other.'/address', '/pairing', '/accounts/'.$account.'/archive'] as $path) {
            $this->client->request('POST', $this->url().$path, ['_token' => 'any', 'autoPairing' => '0']);
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
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    private function changeEnv(string $name, string $value): void
    {
        $this->changedEnv[$name] ??= \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : '';
        $_SERVER[$name] = $_ENV[$name] = $value;
        self::ensureKernelShutdown();
        $this->boot();
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

    private function actionToken(): string
    {
        $crawler = $this->client->request('GET', $this->url());

        return Scalars::text($crawler->filter('[data-row-actions] form input[name="_token"]')->first()->attr('value'));
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

    private function addresses(): InboundAddresses
    {
        return new InboundAddresses('bank+{token}@example.com');
    }

    /**
     * The feed mail with this token hash goes to, as Mail routes it to the feed's address.
     */
    private function route(string $tokenHash): ?FeedRoute
    {
        $address = self::getContainer()->get(InboundAddressStore::class)->routable($tokenHash);

        return $address instanceof InboundAddress ? new DoctrineBankFeedDirectory($this->connection())->route($address->id()) : null;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
