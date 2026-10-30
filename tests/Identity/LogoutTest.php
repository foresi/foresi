<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LogoutTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
        $browser->seed(['Tenant A']);
        $browser->signIn();
    }

    public function testGetDoesNotLogOut(): void
    {
        $this->client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('GET', '/logout?_csrf_token='.$this->logoutToken());
        self::assertResponseStatusCodeSame(405);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testPostWithAValidTokenLogsOut(): void
    {
        $token = $this->logoutToken();

        $this->client->request('POST', '/logout', ['_csrf_token' => $token]);

        self::assertResponseRedirects('http://localhost/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testPostWithoutAValidTokenKeepsTheSession(): void
    {
        $this->client->request('POST', '/logout');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/logout', ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testTheUserMenuLogoutFormPostsAValidToken(): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/logout"]')->form();

        $this->client->submit($form);

        self::assertResponseRedirects('http://localhost/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('http://localhost/login');
    }

    private function logoutToken(): string
    {
        $crawler = $this->client->request('GET', '/');

        return (string) $crawler->filter('form[action="/logout"] input[name="_csrf_token"]')->attr('value');
    }
}
