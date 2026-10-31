<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class LoginTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The test database lives in memory, so every request must share the kernel.
        $this->client->disableReboot();

        $fixtures = new IdentityFixtures(self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
        $fixtures->rebuildSchema();
        $fixtures->join($fixtures->user(), $fixtures->tenant('Tenant A'));
    }

    public function testLoginWithValidCredentialsRedirectsToDashboard(): void
    {
        $this->submit(IdentityFixtures::EMAIL, IdentityFixtures::PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', self::getContainer()->get('translator')->trans('dashboard.title', [], null, 'en'));
        self::assertSelectorTextContains('body', IdentityFixtures::EMAIL);
    }

    public function testLoginIsCaseInsensitiveOnTheEmail(): void
    {
        $this->submit('Testing@Example.COM', IdentityFixtures::PASSWORD);

        self::assertResponseRedirects('/');
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $this->submit(IdentityFixtures::EMAIL, 'wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        self::assertSelectorExists('form input[name="_password"]');
    }

    public function testAFailedLoginMarksBothFieldsKeepsTheEmailAndFocusesTheFirst(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertNotNull($crawler->filter('form')->attr('novalidate'));
        self::assertNull($crawler->filter('#username')->attr('aria-invalid'));
        self::assertSame('Přihlášení', $crawler->filter('title')->text());

        $this->submit(IdentityFixtures::EMAIL, 'wrong-password');
        $crawler = $this->client->followRedirect();

        // The message stays where it was; both fields point at it, as the sign-in names no single field.
        self::assertSame('Chyba: Přihlášení', $crawler->filter('title')->text());
        self::assertSame('login-error', $crawler->filter('[role="alert"]')->attr('id'));
        foreach (['username', 'password'] as $id) {
            self::assertSame('true', $crawler->filter('#'.$id)->attr('aria-invalid'));
            self::assertSame('login-error', $crawler->filter('#'.$id)->attr('aria-describedby'));
            self::assertStringContainsString('border-badge-red-text', (string) $crawler->filter('#'.$id)->attr('class'));
        }
        self::assertSame(['username'], $crawler->filter('[autofocus]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        self::assertSame(IdentityFixtures::EMAIL, $crawler->filter('#username')->attr('value'));
        self::assertNull($crawler->filter('form button[type="submit"]')->attr('disabled'));
    }

    public function testLoginRejectsUnknownUser(): void
    {
        $this->submit(IdentityFixtures::EMAIL, 'wrong-password');
        $this->client->followRedirect();
        $wrongPassword = $this->client->getCrawler()->filter('[role="alert"]')->text();

        $this->submit('nobody@example.com', IdentityFixtures::PASSWORD);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        // Same message, so the form does not reveal which e-mails exist.
        self::assertSelectorTextSame('[role="alert"]', $wrongPassword);
    }

    public function testLoginRejectsAnInvalidCsrfToken(): void
    {
        $this->client->request('GET', '/login');
        $this->client->request('POST', '/login', [
            '_username' => IdentityFixtures::EMAIL,
            '_password' => IdentityFixtures::PASSWORD,
            '_csrf_token' => 'forged',
        ]);

        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testDashboardRequiresLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testLoginFormCarriesACsrfToken(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testLoginIsThrottledAfterFiveFailures(): void
    {
        $this->submit(IdentityFixtures::EMAIL, 'wrong-password');
        $this->client->followRedirect();
        $wrongPassword = $this->client->getCrawler()->filter('[role="alert"]')->text();
        for ($i = 1; $i < 5; ++$i) {
            $this->submit(IdentityFixtures::EMAIL, 'wrong-password');
        }

        $this->submit(IdentityFixtures::EMAIL, IdentityFixtures::PASSWORD);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        // The throttling message is a different one than the plain bad-credentials message.
        self::assertNotSame($wrongPassword, $this->client->getCrawler()->filter('[role="alert"]')->text());
    }

    public function testNewLoginDoesNotInheritTheTenantOfTheSessionBefore(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $fixtures = new IdentityFixtures($em, self::getContainer()->get(TenantContext::class));
        $shared = $fixtures->tenant('Tenant B');
        $other = $fixtures->tenant('Tenant C');
        $second = $fixtures->user('second@example.com');
        $fixtures->join($second, $shared);
        $fixtures->join($second, $other);
        $first = $em->getRepository(User::class)->findOneBy(['email' => IdentityFixtures::EMAIL]);
        self::assertInstanceOf(User::class, $first);
        $fixtures->join($first, $shared);

        // The first user now has two tenants, picks one and the session remembers it.
        $this->submit(IdentityFixtures::EMAIL, IdentityFixtures::PASSWORD);
        $session = $this->client->getRequest()->getSession();
        $session->set('active_tenant', $shared->toString());
        $session->save();
        self::assertSame($shared->toString(), $session->get('active_tenant'));

        // The second user signs in over the same session: no inherited choice, two tenants, so none.
        $this->submit('second@example.com', IdentityFixtures::PASSWORD);
        self::assertResponseRedirects('/');
        self::assertNull($this->client->getRequest()->getSession()->get('active_tenant'));
    }

    private function submit(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form button[type="submit"]')->form(['_username' => $email, '_password' => $password]);
        $this->client->submit($form);
    }
}
