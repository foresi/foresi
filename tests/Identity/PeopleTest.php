<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationToken;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Shared\Application\SystemMail;
use App\Shared\Application\SystemMailer;
use App\Shared\Application\SystemMailUnavailable;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Shared\Infrastructure\Mail\RecordingSystemMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The people of an organisation and the invitations of its owner: who may see and
 * change what, the link that works once and for a week, and the organisation that
 * always keeps an active owner.
 */
final class PeopleTest extends WebTestCase
{
    private const string OWNER = 'owner@example.com';
    private const string MEMBER = 'member@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private IdentityFixtures $fixtures;
    private TenantId $tenant;

    /** @var list<SystemMail> */
    private array $mails = [];

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
    }

    public function testTheOwnerInvitesAMemberByMail(): void
    {
        $this->signIn(self::OWNER);

        $this->invite('jana@example.com', 'Jana Example');

        self::assertResponseRedirects('/settings/people/invitations');
        $mails = $this->queuedMails();
        self::assertCount(1, $mails);
        self::assertSame('jana@example.com', $mails[0]->to);
        self::assertSame('Example Company invites you to Example Company in Foresi', $mails[0]->subject);
        self::assertSame('en', $mails[0]->locale);
        self::assertSame('Example Company', $mails[0]->organisation);
        self::assertMatchesRegularExpression('#http://localhost/invitation/[A-Za-z0-9_-]{43}#', $mails[0]->text);
        self::assertStringContainsString('for 7 days', $mails[0]->text);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The invitation is on its way to jana@example.com.');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Jana Example');

        $invitation = $this->onlyInvitation();
        // Only the hash of the token is stored.
        self::assertSame(InvitationToken::hash($this->tokenOf($mails[0])), $invitation->tokenHash());
        self::assertSame([InvitationToken::hash($this->tokenOf($mails[0]))], $this->em->getConnection()->fetchFirstColumn('SELECT token_hash FROM invitation'));
    }

    public function testInvitingAgainReplacesThePendingInvitation(): void
    {
        $this->signIn(self::OWNER);
        $this->invite('jana@example.com', 'Jana Example');
        $this->invite('jana@example.com', 'Jana Example', locale: 'cs');

        $mails = $this->queuedMails();
        self::assertCount(2, $mails);
        self::assertSame('cs', $mails[1]->locale);
        self::assertCount(1, $this->em->getConnection()->fetchFirstColumn('SELECT id FROM invitation WHERE revoked_at IS NULL'));
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/invitation/'.$this->tokenOf($mails[0]));
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Invitation revoked');
    }

    public function testSomeoneWhoBelongsAlreadyIsNotInvited(): void
    {
        $this->fixtures->join($this->fixtures->user(self::MEMBER), $this->tenant, Role::Member);
        $this->signIn(self::OWNER);

        $this->invite(self::MEMBER, 'Member Example');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This person already belongs to the organisation.');
        self::assertSame([], $this->queuedMails());
    }

    public function testAGuestNeedsAContact(): void
    {
        $this->signIn(self::OWNER);

        $this->invite('alex@example.com', 'Alex Example', 'guest');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose the contact the person represents.');

        $party = $this->contact();
        $this->invite('alex@example.com', 'Alex Example', 'guest', $party);
        self::assertResponseRedirects('/settings/people/invitations');
        self::assertSame($party, $this->onlyInvitation()->party()?->id());
    }

    public function testWithoutSystemMailTheOwnerGetsTheLink(): void
    {
        self::getContainer()->set(SystemMailer::class, new RecordingSystemMailer(new SystemMailUnavailable()));
        $this->signIn(self::OWNER);

        $this->invite('jana@example.com', 'Jana Example');

        self::assertResponseRedirects('/settings/people/invitations/'.$this->onlyInvitation()->id().'/link');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'This installation cannot send e-mail yet');
        $link = (string) $crawler->filter('#invitation-link')->attr('value');
        self::assertMatchesRegularExpression('#^http://localhost/invitation/[A-Za-z0-9_-]{43}$#', $link);
        self::assertSame(InvitationToken::hash(basename($link)), $this->onlyInvitation()->tokenHash());
        // The link is shown once; it cannot be read back from its hash.
        $this->client->request('GET', '/settings/people/invitations/'.$this->onlyInvitation()->id().'/link');
        self::assertResponseRedirects('/settings/people/invitations');
    }

    public function testANewPersonSetsAPasswordJoinsOnceAndIsSignedIn(): void
    {
        $this->signIn(self::OWNER);
        $this->invite('jana@example.com', 'Jana Example');
        $token = $this->tokenOf($this->queuedMails()[0]);
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', '/invitation/'.$token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Join Example Company');
        $form = $crawler->filter('form button[type="submit"]')->form(['name' => 'Jana Example', 'password' => 'short', 'password_repeat' => 'short']);
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'At least 12 characters');

        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['name' => 'Jana Example', 'password' => 'a-long-password-1', 'password_repeat' => 'a-long-password-1']));
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Example Company');

        $user = $this->user('jana@example.com');
        self::assertSame('en', $user->locale());
        self::assertSame([Role::Member], array_map(static fn ($organisation) => $organisation->role, self::getContainer()->get(MembershipDirectory::class)->organisationsOf($user->id())));
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/invitation/'.$token);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Invitation used');
    }

    public function testSomeoneWithAnAccountSignsInFirstThenAccepts(): void
    {
        $this->fixtures->join($this->fixtures->user(self::MEMBER), $this->fixtures->tenant('Example Elsewhere'));
        $this->signIn(self::OWNER);
        $this->invite(self::MEMBER, 'Member Example');
        $token = $this->tokenOf($this->queuedMails()[0]);
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/invitation/'.$token);
        self::assertSelectorTextContains('main', 'has an account already');
        $this->signIn(self::MEMBER);
        self::assertResponseRedirects('http://localhost/invitation/'.$token);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Join Example Company as Team member?');

        $this->client->submit($crawler->filter('form button[type="submit"]')->form());
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(2, self::getContainer()->get(MembershipDirectory::class)->organisationsOf($this->user(self::MEMBER)->id()));
    }

    public function testAnExpiredOrUnknownLinkSaysSo(): void
    {
        $this->signIn(self::OWNER);
        $this->invite('jana@example.com', 'Jana Example');
        $token = $this->tokenOf($this->queuedMails()[0]);
        $this->em->getConnection()->executeStatement("UPDATE invitation SET expires_at = '2020-01-01 00:00:00'");
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/invitation/'.$token);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Invitation expired');

        $this->client->request('GET', '/invitation/'.InvitationToken::generate());
        self::assertResponseStatusCodeSame(404);
        // Without an invitation there is no language chosen for the visitor: the default one.
        self::assertSelectorTextContains('h1', 'Pozvánka nenalezena');
    }

    public function testTheLinkIsRateLimited(): void
    {
        $token = InvitationToken::generate();
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', '/invitation/'.$token);
        }
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/invitation/'.$token);
        self::assertResponseStatusCodeSame(429);
    }

    public function testTheOwnerSendsAgainAndRevokes(): void
    {
        $this->signIn(self::OWNER);
        $this->invite('jana@example.com', 'Jana Example');
        $first = $this->tokenOf($this->queuedMails()[0]);
        $crawler = $this->client->request('GET', '/settings/people/invitations');
        $id = $this->onlyInvitation()->id();

        $this->client->request('POST', '/settings/people/invitations/'.$id.'/resend', ['_token' => $this->actionToken($crawler)]);
        self::assertResponseRedirects('/settings/people/invitations');
        $this->collectMails();
        $second = $this->tokenOf($this->queuedMails()[1]);
        self::assertNotSame($first, $second);

        $this->client->request('POST', '/settings/people/invitations/'.$id.'/revoke', ['_token' => $this->actionToken($crawler)]);
        self::assertResponseRedirects('/settings/people/invitations');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'No pending invitations');

        $this->client->getCookieJar()->clear();
        // Sending again replaced the first link; the revoked second one says so.
        $this->client->request('GET', '/invitation/'.$first);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/invitation/'.$second);
        self::assertResponseStatusCodeSame(410);
    }

    public function testTheOwnerManagesMembersViewAndGuestsAreKeptOut(): void
    {
        $this->fixtures->join($this->fixtures->user(self::MEMBER), $this->tenant, Role::Member);
        $this->fixtures->join($this->fixtures->user('guest@example.com'), $this->tenant, Role::Guest);

        $this->signIn(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('tbody tr'));
        self::assertSelectorExists('a[href="/settings/people/invite"]');
        self::assertSelectorExists('[data-avatar-cell]');
        self::assertSelectorTextContains('tbody', 'Alex Example');
        self::assertCount(1, $crawler->filter('#sidebar a[href="/settings/people"]'));

        $this->client->getCookieJar()->clear();
        $this->signIn(self::MEMBER);
        $crawler = $this->client->request('GET', '/settings/people');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('tbody tr'));
        self::assertSelectorNotExists('a[href="/settings/people/invite"]');
        self::assertSelectorNotExists('[data-row-actions]');
        foreach (['/settings/people/invitations', '/settings/people/invite', '/settings/people/contacts?q=Alex'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->client->getCookieJar()->clear();
        $this->signIn('guest@example.com');
        $this->client->request('GET', '/settings/people');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheContactPickerFindsContactsOfAnyKind(): void
    {
        $this->contact();
        $this->signIn(self::OWNER);

        $this->client->request('GET', '/settings/people/contacts?q=Alex');

        self::assertResponseIsSuccessful();
        self::assertSame(['results' => [['id' => $this->onlyContact(), 'label' => 'Alex Example', 'hint' => 'Person']]], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testARoleChangeToGuestNeedsAContact(): void
    {
        $this->fixtures->join($this->fixtures->user(self::MEMBER), $this->tenant, Role::Member);
        $membership = $this->membershipOf(self::MEMBER);
        $this->signIn(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people/'.$membership.'/edit');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->submit($crawler->filter('form[action$="/edit"] button[type="submit"]')->form(['role' => 'guest']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose the contact the person represents.');

        $party = $this->contact();
        $this->client->submit($crawler->filter('form[action$="/edit"] button[type="submit"]')->form(['role' => 'guest', 'contactId' => $party]));
        self::assertResponseRedirects('/settings/people');
        $this->context->switchTo($this->tenant);
        $changed = $this->em->find(Membership::class, $membership);
        self::assertSame(Role::Guest, $changed?->role());
        self::assertSame($party, $changed->party()?->id());
        $this->context->reset();
    }

    public function testTheLastOwnerIsNeitherDemotedNorDeactivated(): void
    {
        // A member, whose row actions carry the token of the screen.
        $this->fixtures->join($this->fixtures->user(self::MEMBER), $this->tenant, Role::Member);
        $this->signIn(self::OWNER);
        $owner = $this->membershipOf(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people/'.$owner.'/edit');

        $this->client->submit($crawler->filter('form[action$="/edit"] button[type="submit"]')->form(['role' => 'member']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'The organisation must keep at least one active owner.');

        $crawler = $this->client->request('GET', '/settings/people');
        $this->client->request('POST', '/settings/people/'.$owner.'/deactivate', ['_token' => $this->actionToken($crawler)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'You cannot deactivate yourself.');

        // With a second owner, the first may step down.
        $this->fixtures->join($this->fixtures->user('second@example.com'), $this->tenant);
        $crawler = $this->client->request('GET', '/settings/people/'.$owner.'/edit');
        $this->client->submit($crawler->filter('form[action$="/edit"] button[type="submit"]')->form(['role' => 'member']));
        self::assertResponseRedirects('/settings/people');
    }

    public function testADeactivatedPersonNoLongerSignsInToTheOrganisation(): void
    {
        $member = $this->fixtures->user(self::MEMBER);
        $this->fixtures->join($member, $this->tenant, Role::Member);
        $membership = $this->membershipOf(self::MEMBER);
        $this->signIn(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people');

        $this->client->request('POST', '/settings/people/'.$membership.'/deactivate', ['_token' => $this->actionToken($crawler)]);
        self::assertResponseRedirects('/settings/people');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Deactivated');

        self::assertSame([], self::getContainer()->get(MembershipDirectory::class)->organisationsOf($member->id()));
        $this->client->getCookieJar()->clear();
        $this->signIn(self::MEMBER);
        $this->client->request('GET', '/settings/people');
        self::assertResponseStatusCodeSame(403);

        // The membership and its history stay; the owner lets them in again.
        $this->client->getCookieJar()->clear();
        $this->signIn(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people');
        $this->client->request('POST', '/settings/people/'.$membership.'/reactivate', ['_token' => $this->actionToken($crawler)]);
        self::assertCount(1, self::getContainer()->get(MembershipDirectory::class)->organisationsOf($member->id()));
    }

    public function testTheOwnerResetsTheTwoFactorSignInOfSomeoneOnlyHere(): void
    {
        $member = $this->fixtures->user(self::MEMBER);
        $member->enrolTwoFactor('sealed', ['hash']);
        $this->em->flush();
        $this->fixtures->join($member, $this->tenant, Role::Member);
        $membership = $this->membershipOf(self::MEMBER);
        $this->signIn(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people');
        self::assertSelectorTextContains('tbody', 'On');

        $this->client->request('POST', '/settings/people/'.$membership.'/reset-two-factor', ['_token' => $this->actionToken($crawler)]);
        self::assertResponseRedirects('/settings/people');
        self::assertFalse($this->user(self::MEMBER)->hasTwoFactor());

        // Someone who also belongs elsewhere is the operator's to reset.
        $other = $this->user(self::MEMBER);
        $other->enrolTwoFactor('sealed', ['hash']);
        $this->em->flush();
        $this->fixtures->join($other, $this->fixtures->tenant('Example Elsewhere'));
        $this->client->request('POST', '/settings/people/'.$membership.'/reset-two-factor', ['_token' => $this->actionToken($crawler)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'also belongs to another organisation');
        self::assertTrue($this->user(self::MEMBER)->hasTwoFactor());
    }

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => $email, '_password' => IdentityFixtures::PASSWORD]));
    }

    private function invite(string $email, string $name, string $role = 'member', ?string $contact = null, string $locale = 'en'): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/settings/people/invite');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->submit($crawler->filter('form[action="/settings/people/invite"] button[type="submit"]')->form(['email' => $email, 'name' => $name, 'role' => $role, 'contactId' => $contact ?? '', 'locale' => $locale]));
        $this->collectMails();

        return $crawler;
    }

    /**
     * The mail transport is reset with the kernel's services between requests, so
     * the mails are collected after each request that sends.
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

    private function actionToken(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        return (string) $crawler->filter('[data-row-actions] input[name="_token"]')->first()->attr('value');
    }

    /**
     * @return list<SystemMail>
     */
    private function queuedMails(): array
    {
        return $this->mails;
    }

    private function tokenOf(SystemMail $mail): string
    {
        self::assertSame(1, preg_match('#/invitation/([A-Za-z0-9_-]{43})#', $mail->text, $match));

        return $match[1];
    }

    private function onlyInvitation(): Invitation
    {
        $this->context->switchTo($this->tenant);
        $invitations = $this->em->getRepository(Invitation::class)->findBy(['revokedAt' => null]);
        $this->context->reset();
        self::assertCount(1, $invitations);

        return $invitations[0];
    }

    private function contact(): string
    {
        $this->context->switchTo($this->tenant);
        $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $this->em->persist($party);
        $this->em->flush();
        $this->context->reset();

        return $party->id();
    }

    private function membershipOf(string $email): string
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT m.id FROM membership m JOIN app_user u ON u.id = m.user_id WHERE u.email = ? AND m.tenant_id = ?', [$email, $this->tenant->toString()]);
        self::assertCount(1, $ids);
        self::assertIsString($ids[0]);

        return $ids[0];
    }

    private function onlyContact(): string
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM party');
        self::assertCount(1, $ids);
        self::assertIsString($ids[0]);

        return $ids[0];
    }

    private function user(string $email): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
