<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantChoiceTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    public function testUserWithTwoMembershipsIsSentToTheChoicePage(): void
    {
        $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/organisation/choose');
    }

    public function testChoicePageListsOnlyTheUsersOrganisationsEachWithAPostForm(): void
    {
        $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->fixtures->join($this->browser->fixtures->user('other@example.com'), $this->browser->fixtures->tenant('Foreign Tenant'));
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/organisation/choose');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Tenant A');
        self::assertSelectorTextContains('main', 'Tenant B');
        self::assertSelectorTextNotContains('body', 'Foreign Tenant');
        $forms = $crawler->filter('form[action*="/organisation/switch/"]');
        self::assertCount(2, $forms);
        self::assertSame('post', strtolower((string) $forms->first()->attr('method')));
        self::assertSelectorCount(2, 'form[action*="/organisation/switch/"] input[name="_token"]');
        self::assertSelectorCount(1, 'h1');
    }

    public function testChoosingSetsTheTenantAndChangesTheSessionId(): void
    {
        [, $b] = $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/organisation/choose');
        $before = $this->sessionCookie();
        self::assertNotNull($before);

        $this->client->submit($crawler->filter('form[action="/organisation/switch/'.$b->toString().'"]')->form());

        self::assertResponseRedirects('/');
        $after = $this->sessionCookie();
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
        self::assertSame($b->toString(), $this->client->getRequest()->getSession()->get('active_tenant'));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#sidebar', 'Tenant B');
    }

    public function testSwitchingLaterToTheOtherOrganisationWorksToo(): void
    {
        [$a, $b] = $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/organisation/choose');
        $this->client->submit($crawler->filter('form[action="/organisation/switch/'.$a->toString().'"]')->form());
        $crawler = $this->client->request('GET', '/organisation/choose');

        $this->client->submit($crawler->filter('form[action="/organisation/switch/'.$b->toString().'"]')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('#sidebar', 'Tenant B');
    }

    public function testTenantWithoutMembershipIsNotFound(): void
    {
        $this->browser->seed(['Tenant A', 'Tenant B']);
        $foreign = $this->browser->fixtures->tenant('Foreign Tenant');
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/organisation/choose');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $before = $this->sessionCookie();

        $this->client->request('POST', '/organisation/switch/'.$foreign->toString(), ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->client->getRequest()->getSession()->get('active_tenant'));
        self::assertSame($before, $this->sessionCookie());
    }

    public function testUnknownAndMalformedTenantsAreNotFoundEither(): void
    {
        $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/organisation/choose');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/organisation/switch/'.TenantId::generate()->toString(), ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/organisation/switch/not-an-id', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSwitchWithoutAValidCsrfTokenIsRejected(): void
    {
        [, $b] = $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();
        $this->client->request('GET', '/organisation/choose');
        $before = $this->sessionCookie();

        $this->client->request('POST', '/organisation/switch/'.$b->toString());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/organisation/switch/'.$b->toString(), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->client->getRequest()->getSession()->get('active_tenant'));
        self::assertSame($before, $this->sessionCookie());
    }

    public function testGetNeverChangesState(): void
    {
        [, $b] = $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();

        $this->client->request('GET', '/organisation/switch/'.$b->toString());

        self::assertResponseStatusCodeSame(405);
        self::assertNull($this->client->getRequest()->getSession()->get('active_tenant'));
    }

    public function testChoicePageNeedsLogin(): void
    {
        $this->browser->seed(['Tenant A']);

        $this->client->request('GET', '/organisation/choose');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testUserWithoutOrganisationsSeesAnEmptyState(): void
    {
        $this->browser->seed([]);
        $this->browser->signIn();

        $this->client->request('GET', '/organisation/choose');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action*="/organisation/switch/"]');
        self::assertSelectorExists('main h2');
    }

    private function sessionCookie(): ?string
    {
        return $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
    }
}
