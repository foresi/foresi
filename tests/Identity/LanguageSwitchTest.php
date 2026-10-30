<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LanguageSwitchTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
        $this->browser->seed(['Tenant A'], 'en');
        $this->browser->signIn();
    }

    public function testMenuOffersTheOtherLanguageAndSwitchingStoresItOnTheUser(): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/account/language"]');
        self::assertSame('cs', $form->filter('input[name="locale"]')->attr('value'));
        self::assertSame('/', $form->filter('input[name="redirect"]')->attr('value'));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/');
        self::assertSame('cs', $this->storedLocale());
        $this->client->followRedirect();
        self::assertSame('cs', $this->client->getRequest()->getLocale());
        self::assertSelectorTextContains('main h1', 'Přehled');
        self::assertSame('en', $this->client->getCrawler()->filter('form[action="/account/language"] input[name="locale"]')->attr('value'));
    }

    public function testRedirectGoesBackToTheSameOriginPageIncludingTheQuery(): void
    {
        $token = $this->token();

        $this->client->request('POST', '/account/language', ['_token' => $token, 'locale' => 'cs', 'redirect' => '/organisation/choose?x=1']);

        self::assertResponseRedirects('/organisation/choose?x=1');
    }

    #[DataProvider('offSiteTargets')]
    public function testOffSiteRedirectTargetsAreIgnored(string $target): void
    {
        $token = $this->token();

        $this->client->request('POST', '/account/language', ['_token' => $token, 'locale' => 'cs', 'redirect' => $target]);

        // The language is still switched, but the user lands on the dashboard, never off-site.
        self::assertResponseRedirects('/');
        self::assertSame('cs', $this->storedLocale());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function offSiteTargets(): iterable
    {
        yield 'absolute' => ['https://evil.example/phish'];
        yield 'scheme relative' => ['//evil.example'];
        yield 'backslash' => ['/\\evil.example'];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    public function testRequestWithoutAValidCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/account/language', ['locale' => 'cs']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/account/language', ['_token' => 'forged', 'locale' => 'cs']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame('en', $this->storedLocale());
    }

    public function testUnsupportedLocaleIsRejected(): void
    {
        $token = $this->token();

        $this->client->request('POST', '/account/language', ['_token' => $token, 'locale' => 'de']);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/account/language', ['_token' => $token]);
        self::assertResponseStatusCodeSame(400);

        self::assertSame('en', $this->storedLocale());
    }

    public function testGetIsNotAllowed(): void
    {
        $this->client->request('GET', '/account/language?locale=cs');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('en', $this->storedLocale());
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/');

        return (string) $crawler->filter('form[action="/account/language"] input[name="_token"]')->attr('value');
    }

    private function storedLocale(): string
    {
        $em = $this->browser->em();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => IdentityFixtures::EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return $user->locale();
    }
}
