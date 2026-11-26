<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity;

use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Application\SystemMail;
use Foresi\Shared\Application\SystemMailer;
use Foresi\Shared\Application\SystemMailUnavailable;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Shared\Infrastructure\Mail\RecordingSystemMailer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The password in the security part of the account: changed in a dialog with the
 * current password, after which this browser stays signed in with a new session
 * id, every other session ends, trusted browsers ask for a code again and the
 * person gets a notice.
 */
final class PasswordChangeTest extends WebTestCase
{
    private const string NEW_PASSWORD = 'a new long passphrase';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    /** @var list<SystemMail> */
    private array $mails = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->browser->seed();
    }

    public function testThePageExplainsThePasswordAndOpensTheChangeInADialog(): void
    {
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/account/password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Password');
        self::assertStringContainsString(IdentityFixtures::EMAIL, $crawler->filter('[data-password]')->text());
        $action = $crawler->filter('#password-change');
        self::assertSame('/account/password/change', $action->attr('href'));
        self::assertNotNull($action->attr('data-dialog'));
        self::assertContains('/account/password', $crawler->filter('[data-nav-area="profile"][data-shown] a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        $crawler = $this->client->request('GET', '/account/password/change', server: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('dialog #password-form');
        self::assertSame('current-password', $crawler->filter('#current-password')->attr('autocomplete'));
        self::assertSame('password', $crawler->filter('#current-password')->attr('type'));
        self::assertSame('new-password', $crawler->filter('#new-password')->attr('autocomplete'));
        self::assertSame((string) User::MIN_PASSWORD_LENGTH, $crawler->filter('#new-password')->attr('minlength'));
        self::assertSelectorTextContains('#password-form', 'At least 12 characters');
        // Show passwords needs JavaScript, so it waits hidden for it.
        self::assertSame('password-reveal', $crawler->filter('#password-form')->attr('data-controller'));
        self::assertNotNull($crawler->filter('[data-password-reveal-target="toggle"]')->attr('hidden'));
        self::assertCount(1, $crawler->filter('[data-password-reveal-target="toggle"] input#show-passwords[type="checkbox"]'));
        self::assertCount(2, $crawler->filter('[data-password-reveal-target="input"]'));
    }

    public function testAWrongCurrentPasswordOrAShortNewOneChangesNothing(): void
    {
        $this->browser->signIn();

        $this->change('not-the-password', self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#password-form', 'The current password is not right.');
        self::assertSame('true', $this->client->getCrawler()->filter('#current-password')->attr('aria-invalid'));

        $this->change(IdentityFixtures::PASSWORD, 'too-short');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#password-form', 'A password has at least 12 characters.');
        self::assertSame('true', $this->client->getCrawler()->filter('#new-password')->attr('aria-invalid'));

        self::assertTrue(password_verify(IdentityFixtures::PASSWORD, $this->user()->passwordHash()));
        self::assertSame([], $this->mails());
    }

    public function testWrongCurrentPasswordsAreThrottled(): void
    {
        $this->browser->signIn();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->change('not-the-password', self::NEW_PASSWORD);
        }
        $this->change(IdentityFixtures::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#password-form', 'Too many attempts. Try again in');
        self::assertTrue(password_verify(IdentityFixtures::PASSWORD, $this->user()->passwordHash()));
    }

    public function testAChangeKeepsThisBrowserSignedInAndEndsEveryOtherSession(): void
    {
        // Another device: signed in first, its session set aside.
        $this->browser->signIn();
        $other = $this->sessionId();
        $this->client->getCookieJar()->clear();
        $this->browser->signIn();
        $before = $this->sessionId();
        self::assertNotSame($other, $before);

        $this->change(IdentityFixtures::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/account/password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Your password is changed. Your other devices were signed out.');
        self::assertNotSame($before, $this->sessionId(), 'The session id changes.');
        $this->client->request('GET', '/account/profile');
        self::assertResponseIsSuccessful();

        $user = $this->user();
        self::assertTrue(password_verify(self::NEW_PASSWORD, $user->passwordHash()));
        self::assertSame(1, $user->trustedDeviceVersion());

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(IdentityFixtures::EMAIL, $mails[0]->to);
        self::assertSame('en', $mails[0]->locale);
        self::assertSame('Your Foresi password was changed', $mails[0]->subject);
        self::assertStringContainsString('was changed on', $mails[0]->text);
        self::assertStringContainsString('signed out', $mails[0]->text);
        self::assertStringContainsString("contact your firm's administrator", $mails[0]->text);

        // The other device is signed out on its next request.
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('MOCKSESSID', $other));
        $this->client->request('GET', '/account/profile');
        self::assertResponseRedirects('http://localhost/login');

        // The new password signs in, the old one no longer does.
        $this->client->getCookieJar()->clear();
        $this->signIn(IdentityFixtures::PASSWORD);
        self::assertResponseRedirects('http://localhost/login');
        $this->signIn(self::NEW_PASSWORD);
        self::assertResponseRedirects('/');
    }

    public function testWithoutSystemMailThePasswordIsStillChanged(): void
    {
        self::getContainer()->set(SystemMailer::class, new RecordingSystemMailer(new SystemMailUnavailable()));
        $this->browser->signIn();

        $this->change(IdentityFixtures::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/account/password');
        self::assertTrue(password_verify(self::NEW_PASSWORD, $this->user()->passwordHash()));
    }

    private function change(string $current, string $new): void
    {
        $crawler = $this->client->request('GET', '/account/password/change');
        $this->client->submit($crawler->filter('#password-form')->form(['currentPassword' => $current, 'newPassword' => $new]));
        $this->collectMails();
    }

    private function signIn(string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => IdentityFixtures::EMAIL, '_password' => $password]));
    }

    private function sessionId(): string
    {
        return (string) $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
    }

    private function user(): User
    {
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        return self::getContainer()->get(UserRepository::class)->findByEmail(IdentityFixtures::EMAIL) ?? throw new \LogicException('The fixture user is missing.');
    }

    /**
     * @return list<SystemMail>
     */
    private function mails(): array
    {
        return $this->mails;
    }

    /**
     * The mail transport is reset with the kernel's services between requests, so
     * the mails are collected after the request that sends.
     */
    private function collectMails(): void
    {
        $transport = self::getContainer()->get('messenger.transport.mail');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SystemMail) {
                $this->mails[] = $envelope->getMessage();
            }
        }
        $transport->reset();
    }
}
