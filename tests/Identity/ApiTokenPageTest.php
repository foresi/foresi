<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Application\ApiTokens;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenSecret;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Application\Sealer;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The API access screen in the settings: a person issues, lists and revokes
 * their own tokens, an owner those of the other people too, the secret of a new
 * token is shown once and only in the answer that issued it.
 */
final class ApiTokenPageTest extends WebTestCase
{
    private const string OWNER = 'owner@example.com';
    private const string MEMBER = 'member@example.com';
    private const string TOKEN_ID = 'api_tokens';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private IdentityFixtures $fixtures;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->fixtures = new IdentityFixtures($this->em, $this->context);
        $this->fixtures->rebuildSchema();
        $this->tenant = $this->fixtures->tenant('Example Company');
        $this->fixtures->join($this->fixtures->user(self::OWNER), $this->tenant);
        $this->fixtures->join($this->fixtures->user(self::MEMBER), $this->tenant, Role::Member);
    }

    public function testWithoutTokensThePageAsksToCreateOne(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        $crawler = $this->client->request('GET', '/settings/api');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'API access');
        self::assertSelectorTextContains('main', 'No API tokens');
        self::assertSame('/settings/api/new', $crawler->filter('#api-token-new')->attr('href'));
        self::assertSame('dialog', $crawler->filter('#api-token-new')->attr('aria-haspopup'));
        self::assertNotNull($crawler->filter('#api-token-new')->attr('data-dialog'));
        self::assertCount(0, $crawler->filter('[data-api-token-two-factor]'));
        self::assertCount(0, $crawler->filter('[data-api-tokens="others"]'));
        self::assertContains('/settings/api', $crawler->filter('#sidebar a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testWithoutTwoFactorThereIsNoNewTokenOnlyTheWayToSetItUp(): void
    {
        $this->signIn(self::MEMBER);

        $crawler = $this->client->request('GET', '/settings/api');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#api-token-new'));
        self::assertCount(1, $crawler->filter('[data-api-token-two-factor] a[href="/account/two-factor"]'));
        self::assertSelectorTextContains('[data-api-token-two-factor]', 'A token can only be created with two-factor sign-in.');
        self::assertSame(0, $this->stored(self::MEMBER));
    }

    public function testWithoutTwoFactorTheFormIsRefusedOnTheServerToo(): void
    {
        $this->signIn(self::MEMBER);

        $crawler = $this->client->request('GET', '/settings/api/new');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form#api-token-form'));
        self::assertCount(1, $crawler->filter('a[href="/account/two-factor"]'));

        $this->issue('Reporting', '90');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'A token can only be created with two-factor sign-in.');
        self::assertStringNotContainsString('foresi_pat_', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->stored(self::MEMBER));
    }

    public function testTheFormOffersThreeLifetimesAndAFixedAccess(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        $crawler = $this->client->request('GET', '/settings/api/new');

        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="days"] option');
        self::assertSame(['30', '90', '365'], $options->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['30 days', '90 days', '365 days'], $options->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame('90', $crawler->filter('select[name="days"] option[selected]')->attr('value'));
        self::assertSame('Read', $crawler->filter('#access')->attr('value'));
        self::assertSame('', $crawler->filter('#access')->attr('name'), 'The access is not a choice.');
        self::assertNotNull($crawler->filter('#access')->attr('readonly'));
        self::assertSame('80', $crawler->filter('input[name="name"]')->attr('maxlength'));
    }

    public function testTheFormIsADialogForTheDialogHost(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        $this->client->request('GET', '/settings/api/new', server: ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<dialog', $content);
        self::assertStringNotContainsString('id="sidebar"', $content);
    }

    public function testTheSecretIsShownOnceInTheAnswerThatIssuedIt(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        $crawler = $this->client->request('GET', '/settings/api/new');
        $this->client->submit($crawler->filter('form#api-token-form')->form(['name' => '  Reporting  ', 'days' => '365']));

        // The answer itself, not a redirect: the secret is in no URL, session or flash message.
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        $page = $this->client->getCrawler();
        $secret = (string) $page->filter('input#api-token-secret')->attr('value');
        self::assertMatchesRegularExpression('/^'.ApiTokenSecret::PATTERN.'$/', $secret);
        self::assertNotNull($page->filter('input#api-token-secret')->attr('readonly'));
        self::assertCount(1, $page->filter('[data-controller="copy"] button[data-action="copy#copy"]'));
        self::assertSelectorTextContains('[data-api-token-once]', 'shown only this once');
        $expires = new \DateTimeImmutable('+365 days')->format('Y-m-d');
        self::assertSelectorTextContains('main', 'The token Reporting works until '.$expires.'.');
        self::assertSelectorTextContains('main', 'docs/api.md');
        self::assertSelectorTextContains('main', 'docs/mcp.md');
        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');

        $stored = $this->tokens(self::MEMBER);
        self::assertCount(1, $stored);
        self::assertSame('Reporting', $stored[0]->name());
        self::assertSame([ApiScope::Read], $stored[0]->scopes());
        self::assertSame(substr($secret, -4), $stored[0]->lastFour());
        self::assertSame($expires, $stored[0]->expiresAt()->format('Y-m-d'));

        // The list shows the token by its last characters, never the secret, also not on a reload.
        $list = $this->client->request('GET', '/settings/api');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('foresi_pat_', (string) $this->client->getResponse()->getContent());
        $row = $list->filter('[data-api-tokens="mine"] tbody tr');
        self::assertCount(1, $row);
        $cells = $row->filter('td')->each(static fn (Crawler $cell): string => trim((string) preg_replace('/\s+/', ' ', $cell->text())));
        self::assertSame(['Reporting…'.substr($secret, -4), 'Read', '…'.substr($secret, -4), date('Y-m-d'), $expires, 'Never'], \array_slice($cells, 0, 6));
    }

    public function testTheDialogShowsTheSecretAndLoadsTheListAgainOnClosing(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        $this->issue('Reporting', '30', dialog: true);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('dialog#dialog-page[data-dialog-reload="1"]'));
        self::assertMatchesRegularExpression('/^'.ApiTokenSecret::PATTERN.'$/', (string) $crawler->filter('input#api-token-secret')->attr('value'));
        self::assertStringNotContainsString('id="sidebar"', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->stored(self::MEMBER));
    }

    public function testAnEmptyNameIsRefusedWithTheFormAgain(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        $this->issue('   ', '90');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#name-error', 'Enter a name of up to 80 characters.');
        self::assertSame('90', $this->client->getCrawler()->filter('select[name="days"] option[selected]')->attr('value'));
        self::assertSame(0, $this->stored(self::MEMBER));
    }

    public function testAForeignLifetimeIsRefused(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        foreach (['7', '', 'abc', '-30', '9999'] as $days) {
            $this->issue('Reporting', $days);

            self::assertResponseStatusCodeSame(422, $days);
            self::assertSelectorTextContains('#days-error', 'Choose how long the token lasts.');
        }
        self::assertSame(0, $this->stored(self::MEMBER));
    }

    public function testANameOfEightyCharactersIsKeptAndOneMoreIsRefused(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        $this->issue(str_repeat('n', 81), '90');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->stored(self::MEMBER));

        $this->issue(str_repeat('n', 80), '90');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->stored(self::MEMBER));
    }

    public function testNoTokenIsIssuedWithoutTheCsrfToken(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);

        $this->client->request('POST', '/settings/api/new', ['_token' => 'forged', 'name' => 'Reporting', 'days' => '90']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/settings/api/new', ['name' => 'Reporting', 'days' => '90']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->stored(self::MEMBER));
    }

    public function testAnExpiredAndARevokedTokenSayWhatBecameOfThem(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);
        $this->store(self::MEMBER, 'Active', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+10 days'));
        $this->store(self::MEMBER, 'Old', new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('-1 second'));
        $this->store(self::MEMBER, 'Gone', new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('+10 days'), revoked: true);

        $crawler = $this->client->request('GET', '/settings/api');

        $rows = $crawler->filter('[data-api-tokens="mine"] tbody tr');
        self::assertCount(3, $rows);
        $byName = [];
        $rows->each(function (Crawler $row) use (&$byName): void {
            $byName[trim($row->filter('td')->first()->children()->first()->text())] = $row;
        });
        self::assertSame(['Active', 'Gone', 'Old'], $this->sorted(array_keys($byName)));
        self::assertStringNotContainsString('Expired', $byName['Active']->text());
        self::assertStringNotContainsString('Revoked', $byName['Active']->text());
        self::assertStringContainsString('Expired', $byName['Old']->text());
        self::assertStringContainsString('Revoked', $byName['Gone']->text());
        self::assertCount(1, $byName['Active']->filter('form[action$="/revoke"]'));
        self::assertCount(0, $byName['Old']->filter('form[action$="/revoke"]'));
        self::assertCount(0, $byName['Gone']->filter('form[action$="/revoke"]'));
    }

    public function testTheLastUseShowsWhenThereWasOne(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);
        $id = $this->store(self::MEMBER, 'Reporting', new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('+10 days'));
        $used = new \DateTimeImmutable('2026-11-02 14:35:00');
        self::getContainer()->get(ApiTokens::class)->touch($id, $used, $used->modify('-1 hour'));

        $crawler = $this->client->request('GET', '/settings/api');

        self::assertStringContainsString('2026-11-02 14:35', $crawler->filter('[data-api-tokens="mine"] tbody tr')->text());
        self::assertStringNotContainsString('Never', $crawler->filter('[data-api-tokens="mine"] tbody tr')->text());
    }

    public function testAPersonRevokesTheirOwnTokenAfterConfirming(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);
        $id = $this->store(self::MEMBER, 'Reporting', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+10 days'));

        $crawler = $this->client->request('GET', '/settings/api');
        $form = $crawler->filter('form[action="/settings/api/'.$id.'/revoke"]');
        self::assertCount(1, $form);
        self::assertSame('Revoke the token Reporting?', $form->attr('data-turbo-confirm'));
        self::assertSame('danger', $form->attr('data-confirm-tone'));
        self::assertSame('Revoke', $form->attr('data-confirm-accept'));
        self::assertNotNull($form->attr('data-confirm-text'));
        $this->client->submit($form->form());

        self::assertResponseRedirects('/settings/api');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The token is revoked.');
        self::assertStringContainsString('Revoked', $crawler->filter('[data-api-tokens="mine"] tbody tr')->text());
        self::assertCount(0, $crawler->filter('form[action$="/revoke"]'));
        $token = $this->tokens(self::MEMBER)[0];
        self::assertInstanceOf(\DateTimeImmutable::class, $token->revokedAt());
        self::assertSame($this->userId(self::MEMBER), $token->revokedBy());
    }

    public function testRevokingNeedsTheCsrfToken(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);
        $id = $this->store(self::MEMBER, 'Reporting', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+10 days'));

        $this->client->request('POST', '/settings/api/'.$id.'/revoke', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/settings/api/'.$id.'/revoke');
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->tokens(self::MEMBER)[0]->revokedAt());
    }

    public function testRevokingAnUnknownTokenIsNotFound(): void
    {
        $this->signIn(self::MEMBER, twoFactor: true);
        $this->client->request('GET', '/settings/api');

        $this->client->request('POST', '/settings/api/0198a4f0-8b1e-7000-8000-000000000001/revoke', ['_token' => $this->csrf()]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMemberSeesNoOneElsesTokensAndCannotRevokeThem(): void
    {
        $id = $this->store(self::OWNER, 'Owner reporting', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+10 days'));
        $this->signIn(self::MEMBER, twoFactor: true);

        $crawler = $this->client->request('GET', '/settings/api');

        self::assertStringNotContainsString('Owner reporting', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-api-tokens="others"]'));
        $this->client->request('POST', '/settings/api/'.$id.'/revoke', ['_token' => $this->csrf()]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->tokens(self::OWNER)[0]->revokedAt());
    }

    public function testTheOwnerSeesAndRevokesTheTokensOfOthers(): void
    {
        $this->store(self::OWNER, 'Owner reporting', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+10 days'));
        $theirs = $this->store(self::MEMBER, 'Member reporting', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+10 days'));
        $this->signIn(self::OWNER, twoFactor: true);

        $crawler = $this->client->request('GET', '/settings/api');

        $mine = $crawler->filter('[data-api-tokens="mine"] tbody tr');
        self::assertCount(1, $mine);
        self::assertStringContainsString('Owner reporting', $mine->text());
        $others = $crawler->filter('[data-api-tokens="others"] tbody tr');
        self::assertCount(1, $others);
        self::assertStringContainsString('Member reporting', $others->text());
        self::assertStringContainsString(self::MEMBER, $others->text());
        $heads = $crawler->filter('[data-api-tokens="others"] thead th')->each(static fn (Crawler $head): string => trim($head->text()));
        self::assertSame(['Name', 'Person', 'E-mail', 'Access', 'Token', 'Created', 'Expires', 'Last used'], \array_slice($heads, 0, 8));
        self::assertCount(1, $crawler->filter('[data-api-tokens="others"] form[action="/settings/api/'.$theirs.'/revoke"]'));

        $this->client->submit($crawler->filter('form[action="/settings/api/'.$theirs.'/revoke"]')->form());

        self::assertResponseRedirects('/settings/api');
        self::assertInstanceOf(\DateTimeImmutable::class, $this->tokens(self::MEMBER)[0]->revokedAt());
        self::assertSame($this->userId(self::OWNER), $this->tokens(self::MEMBER)[0]->revokedBy());
        self::assertNull($this->tokens(self::OWNER)[0]->revokedAt());
    }

    public function testTheOwnerSeesWhenNoOneElseHasTokens(): void
    {
        $this->signIn(self::OWNER, twoFactor: true);

        $this->client->request('GET', '/settings/api');

        self::assertSelectorTextContains('[data-api-tokens="others"]', 'The other people have no tokens.');
    }

    public function testATokenOfAnotherOrganisationIsNotFound(): void
    {
        $other = $this->fixtures->tenant('Other Company');
        $stranger = $this->fixtures->user('stranger@example.com');
        $this->fixtures->join($stranger, $other);
        $this->context->switchTo($other);
        $token = new ApiToken($stranger->id(), 'Elsewhere', [ApiScope::Read], ApiTokenSecret::hash(ApiTokenSecret::generate()), 'abcd', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+10 days'));
        $this->em->persist($token);
        $this->em->flush();
        $this->context->reset();
        $this->signIn(self::OWNER, twoFactor: true);
        $this->client->request('GET', '/settings/api');

        $this->client->request('POST', '/settings/api/'.$token->id().'/revoke', ['_token' => $this->csrf()]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestReachesNoneOfIt(): void
    {
        $this->context->switchTo($this->tenant);
        $this->fixtures->join($this->fixtures->user('guest@example.com'), $this->tenant, Role::Guest);
        $this->signIn('guest@example.com');

        foreach ([['GET', '/settings/api'], ['GET', '/settings/api/new'], ['POST', '/settings/api/new'], ['POST', '/settings/api/0198a4f0-8b1e-7000-8000-000000000001/revoke']] as [$method, $url]) {
            $this->client->request($method, $url, 'POST' === $method ? ['_token' => 'x', 'name' => 'Reporting', 'days' => '90'] : []);

            self::assertResponseStatusCodeSame(403, $method.' '.$url);
        }
    }

    private function signIn(string $email, bool $twoFactor = false): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => $email, '_password' => IdentityFixtures::PASSWORD]));
        if ($twoFactor) {
            // After the sign-in, which would otherwise ask for a code.
            $user = $this->userEntity($email);
            $user->enrolTwoFactor(self::getContainer()->get(Sealer::class)->seal('JBSWY3DPEHPK3PXP'), ['recovery-hash']);
            self::getContainer()->get(UserRepository::class)->save($user);
        }
    }

    private function issue(string $name, string $days, bool $dialog = false): void
    {
        $this->client->request('POST', '/settings/api/new', ['_token' => $this->csrf(), 'name' => $name, 'days' => $days], server: $dialog ? ['HTTP_X_FORESI_DIALOG' => '1'] : []);
    }

    /**
     * The token of the session, which the list page puts in it.
     */
    private function csrf(): string
    {
        $this->client->request('GET', '/settings/api');
        $stack = self::getContainer()->get('request_stack');
        $stack->push($this->client->getRequest());
        try {
            return self::getContainer()->get('security.csrf.token_manager')->getToken(self::TOKEN_ID)->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function userEntity(string $email): User
    {
        $this->em->clear();

        return self::getContainer()->get(UserRepository::class)->findByEmail($email) ?? throw new \LogicException('The user is missing.');
    }

    private function userId(string $email): string
    {
        return $this->userEntity($email)->id();
    }

    private function store(string $email, string $name, \DateTimeImmutable $created, \DateTimeImmutable $expires, bool $revoked = false): string
    {
        $secret = ApiTokenSecret::generate();
        $token = new ApiToken($this->userId($email), $name, [ApiScope::Read], ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $created, $expires);
        if ($revoked) {
            $token->revoke(null, $created);
        }
        $this->context->switchTo($this->tenant);
        $this->em->persist($token);
        $this->em->flush();
        $this->context->reset();

        return $token->id();
    }

    /**
     * @return list<ApiToken>
     */
    private function tokens(string $email): array
    {
        $userId = $this->userId($email);
        $this->context->switchTo($this->tenant);
        try {
            return $this->em->getRepository(ApiToken::class)->findBy(['userId' => $userId]);
        } finally {
            $this->context->reset();
        }
    }

    private function stored(string $email): int
    {
        return \count($this->tokens($email));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
