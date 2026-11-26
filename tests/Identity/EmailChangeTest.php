<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity;

use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The sign-in e-mail in the security part of the account: after "Confirm it's
 * you" a new address is entered in a dialog, a link goes there and works for a
 * day, the page shows the change waiting with Cancel, and opening the link
 * switches the address, ends every other session and notices the old address.
 */
final class EmailChangeTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NEW_EMAIL = 'new@example.com';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    /** @var list<SystemMail> */
    private array $mails = [];

    protected function setUp(): void
    {
        self::mockTime('2026-11-26 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->browser->seed();
    }

    public function testThePageShowsTheAddressAndOpensTheChangeInADialog(): void
    {
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/account/email');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Sign-in e-mail');
        self::assertStringContainsString(IdentityFixtures::EMAIL, $crawler->filter('[data-email]')->text());
        self::assertSelectorNotExists('[data-email-pending]');
        self::assertSelectorNotExists('#email-cancel');
        $action = $crawler->filter('#email-change');
        self::assertSame('/account/email/change', $action->attr('href'));
        self::assertNotNull($action->attr('data-dialog'));
        $links = $crawler->filter('[data-nav-area="profile"][data-shown] a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertSame(['/account/password', '/account/email', '/account/two-factor'], array_values(array_filter($links, static fn (string $link): bool => \in_array($link, ['/account/password', '/account/email', '/account/two-factor'], true))));

        $crawler = $this->client->request('GET', '/account/email/change', server: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('dialog #email-form');
        self::assertSame('email', $crawler->filter('#new-email')->attr('type'));
        self::assertSame('email', $crawler->filter('#new-email')->attr('autocomplete'));
        self::assertSelectorTextContains('#email-form', 'it works for 24 hours');
    }

    public function testTheChangeAsksToConfirmItIsThePersonFirst(): void
    {
        $this->browser->signIn();
        self::mockTime('+15 minutes');

        $this->client->request('GET', '/account/email/change');

        self::assertResponseRedirects('/account/confirm?reason=email&target=/account/email/change');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-reauthenticate]', 'change the e-mail you sign in with');
        self::assertSelectorExists('a[href="/account/email"]');
    }

    public function testAnUnusableAddressIsRefusedInTheDialog(): void
    {
        $this->browser->signIn();
        $this->addUser('other@example.com', 'Other Person');

        foreach (['not-an-address' => 'Enter a valid e-mail address.', ' TESTING@example.com ' => 'This is the address you sign in with already.', 'Other@Example.com' => 'Another account uses this address.'] as $email => $message) {
            $this->start($email);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('#email-form', $message);
            self::assertSame('true', $this->client->getCrawler()->filter('#new-email')->attr('aria-invalid'));
            self::assertSame($email, $this->client->getCrawler()->filter('#new-email')->attr('value'));
        }
        self::assertNull($this->user()->pendingEmail(new \DateTimeImmutable()));
        self::assertSame([], $this->mails);
    }

    public function testWithoutSystemMailNoChangeStarts(): void
    {
        self::getContainer()->set(SystemMailer::class, new RecordingSystemMailer(new SystemMailUnavailable()));
        $this->browser->signIn();

        $this->start(self::NEW_EMAIL);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#email-form', 'E-mail sending is not set up');
        self::assertNull($this->user()->pendingEmail(new \DateTimeImmutable()));
    }

    public function testStartsAreLimitedPerHour(): void
    {
        $this->browser->signIn();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->start('new'.$attempt.'@example.com');
            self::assertResponseRedirects('/account/email');
        }
        $this->start(self::NEW_EMAIL);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#email-form', 'Too many changes. Try again in');
        self::assertSame('new4@example.com', $this->user()->pendingEmail(new \DateTimeImmutable()));
    }

    public function testAStartedChangeWaitsForItsLinkAndCanBeCancelled(): void
    {
        $this->browser->signIn();

        $this->start(' New@Example.com ');

        self::assertResponseRedirects('/account/email');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'We sent a link to new@example.com.');
        self::assertStringContainsString('new@example.com', $crawler->filter('[data-email-pending]')->text());
        self::assertStringContainsString('Nov 27, 2026', $crawler->filter('[data-email-pending]')->text());
        self::assertSame(IdentityFixtures::EMAIL, $this->user()->email());

        self::assertCount(1, $this->mails);
        $mail = $this->mails[0];
        self::assertSame(self::NEW_EMAIL, $mail->to);
        self::assertSame('en', $mail->locale);
        self::assertSame('Confirm your new Foresi e-mail', $mail->subject);
        self::assertStringContainsString('Example Company asked to sign in to Foresi with new@example.com instead of testing@example.com.', $mail->text);
        self::assertStringContainsString('The link works for 24 hours.', $mail->text);
        self::assertStringContainsString('href="http://localhost'.$this->link($mail).'"', $mail->html);

        // The old address still signs in.
        $this->client->getCookieJar()->clear();
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/account/email');
        $this->client->submit($crawler->filter('[data-email-cancel]')->form());
        self::assertResponseRedirects('/account/email');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The change of your e-mail is cancelled.');
        self::assertSelectorNotExists('[data-email-pending]');

        $this->client->request('GET', $this->link($mail));
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('[data-email-confirm="unknown"]', 'The link has expired, was cancelled or has been used already.');
    }

    public function testCancelNeedsTheFormsToken(): void
    {
        $this->browser->signIn();

        $this->client->request('POST', '/account/email/cancel', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheLinkSwitchesTheAddressKeepsThisBrowserAndEndsEveryOtherSession(): void
    {
        $this->addUser('owner@example.com', 'Olga Owner', Role::Owner);
        // Another device: signed in first, its session set aside.
        $this->browser->signIn();
        $other = $this->sessionId();
        $this->client->getCookieJar()->clear();
        $this->browser->signIn();
        $this->start(self::NEW_EMAIL);
        $link = $this->link($this->mails[0]);

        // A mail scanner opening the link changes nothing.
        $crawler = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-email-confirm="confirm"]', 'You will sign in with new@example.com instead of testing@example.com.');
        self::assertSame(IdentityFixtures::EMAIL, $this->user()->email());

        $before = $this->sessionId();
        $this->client->submit($crawler->filter('[data-email-confirm] form')->form());
        $this->collectMails();

        self::assertResponseRedirects('/account/email');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'You now sign in with new@example.com. Your other devices were signed out.');
        self::assertNotSame($before, $this->sessionId(), 'The session id changes.');
        $this->client->request('GET', '/account/profile');
        self::assertResponseIsSuccessful();

        $user = $this->self(self::NEW_EMAIL);
        self::assertSame(1, $user->sessionVersion());
        self::assertNull($this->users()->findByEmail(IdentityFixtures::EMAIL));

        self::assertCount(2, $this->mails);
        $notice = $this->mails[1];
        self::assertSame(IdentityFixtures::EMAIL, $notice->to);
        self::assertSame('Your Foresi sign-in e-mail was changed', $notice->subject);
        self::assertStringContainsString('was changed from testing@example.com to new@example.com on', $notice->text);
        self::assertStringContainsString('signed out', $notice->text);
        self::assertStringContainsString('contact your firm right away: Olga Owner.', $notice->text);

        // The other device is signed out on its next request.
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('MOCKSESSID', $other));
        $this->client->request('GET', '/account/profile');
        self::assertResponseRedirects('http://localhost/login');

        // The new address signs in, the old one no longer does; the link works once.
        $this->client->getCookieJar()->clear();
        $this->signIn(IdentityFixtures::EMAIL);
        self::assertResponseRedirects('http://localhost/login');
        $this->signIn(self::NEW_EMAIL);
        self::assertResponseRedirects('/');
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLinkWorksInABrowserThatIsNotSignedIn(): void
    {
        $this->self(IdentityFixtures::EMAIL)->changeLocale('cs');
        $this->browser->em()->flush();
        $this->browser->signIn();
        $this->start(self::NEW_EMAIL);
        $link = $this->link($this->mails[0]);
        self::assertSame('cs', $this->mails[0]->locale);
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', $link);
        self::assertSelectorTextContains('[data-email-confirm="confirm"]', 'Místo adresou testing@example.com');
        $this->client->submit($crawler->filter('[data-email-confirm] form')->form());
        $this->collectMails();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-email-confirm="done"]', 'new@example.com');
        self::assertSelectorExists('[data-email-confirm="done"] a[href="/login"]');
        self::assertSame(self::NEW_EMAIL, $this->user(self::NEW_EMAIL)->email());
        // No owner besides the person: the notice points to the installation's administrator.
        self::assertStringContainsString('správce své instalace Foresi', $this->mails[1]->text);
    }

    public function testTheLinkConfirmsNoAddressTakenMeanwhile(): void
    {
        $this->browser->signIn();
        $this->start(self::NEW_EMAIL);
        $link = $this->link($this->mails[0]);
        $crawler = $this->client->request('GET', $link);
        $this->addUser(self::NEW_EMAIL, 'Quicker Person');

        $this->client->submit($crawler->filter('[data-email-confirm] form')->form());

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('[data-email-confirm="taken"]', 'Another account started using new@example.com');
        self::assertSame(IdentityFixtures::EMAIL, $this->self(IdentityFixtures::EMAIL)->email());
    }

    public function testTheLinkExpiresAfterADay(): void
    {
        $this->browser->signIn();
        $this->start(self::NEW_EMAIL);
        $link = $this->link($this->mails[0]);
        $crawler = $this->client->request('GET', $link);
        self::mockTime('+24 hours');

        $this->client->submit($crawler->filter('[data-email-confirm] form')->form());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/account/email');
        self::assertSelectorNotExists('[data-email-pending]');
        self::assertSame(IdentityFixtures::EMAIL, $this->user()->email());
    }

    public function testTheConfirmationNeedsTheFormsToken(): void
    {
        $this->browser->signIn();
        $this->start(self::NEW_EMAIL);

        $this->client->request('POST', $this->link($this->mails[0]), ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(IdentityFixtures::EMAIL, $this->user()->email());
    }

    private function start(string $email): void
    {
        $crawler = $this->client->request('GET', '/account/email/change');
        $this->client->submit($crawler->filter('#email-form')->form(['newEmail' => $email]));
        $this->collectMails();
    }

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => $email, '_password' => IdentityFixtures::PASSWORD]));
    }

    private function link(SystemMail $mail): string
    {
        self::assertSame(1, preg_match('#http://localhost(/account/email/confirm/[A-Za-z0-9_-]{43})#', $mail->text, $match));

        return $match[1];
    }

    private function addUser(string $email, string $name, ?Role $role = null): void
    {
        $em = $this->browser->em();
        $user = new User($email, 'hash', $name);
        $em->persist($user);
        if (null !== $role) {
            $em->persist(new Membership($user, $role, null));
        }
        $em->flush();
    }

    private function sessionId(): string
    {
        return (string) $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    /**
     * The person as the database holds them now.
     */
    private function user(string $email = IdentityFixtures::EMAIL): User
    {
        $this->browser->em()->clear();

        return $this->self($email);
    }

    private function self(string $email): User
    {
        return $this->users()->findByEmail($email) ?? throw new \LogicException('The user is missing.');
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
