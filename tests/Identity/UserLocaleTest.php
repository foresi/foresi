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

final class UserLocaleTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testDefaultLocaleIsCzech(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $this->translate('login.title', 'cs'));
        self::assertSame('cs', $this->client->getRequest()->getLocale());
        self::assertSame('cs', $this->client->getCrawler()->filter('html')->attr('lang'));
    }

    public function testAuthenticatedUserLocaleIsApplied(): void
    {
        $this->signIn('en');

        self::assertResponseIsSuccessful();
        self::assertSame('en', $this->client->getRequest()->getLocale());
        self::assertSelectorTextContains('title', $this->translate('dashboard.title', 'en'));
        self::assertSelectorTextContains('h1', $this->translate('dashboard.title', 'en'));
    }

    public function testCzechUserSeesCzechPages(): void
    {
        $this->signIn('cs');

        self::assertResponseIsSuccessful();
        self::assertSame('cs', $this->client->getRequest()->getLocale());
        self::assertSelectorTextContains('h1', $this->translate('dashboard.title', 'cs'));
        self::assertNotSame($this->translate('dashboard.title', 'cs'), $this->translate('dashboard.title', 'en'));
    }

    private function translate(string $key, string $locale): string
    {
        return self::getContainer()->get('translator')->trans($key, [], null, $locale);
    }

    private function signIn(string $locale): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $fixtures = new IdentityFixtures($em, self::getContainer()->get(TenantContext::class));
        $fixtures->rebuildSchema();
        $user = new User(IdentityFixtures::EMAIL, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Example Company', $locale);
        $em->persist($user);
        $em->flush();
        $fixtures->join($user, $fixtures->tenant('Tenant A'));

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form')->form(['_username' => IdentityFixtures::EMAIL, '_password' => IdentityFixtures::PASSWORD]));
        $this->client->followRedirect();
    }
}
