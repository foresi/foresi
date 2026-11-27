<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Contacts;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * With the address book installed, the people screens offer the contact a person
 * represents: the field of the invitation and of the edit form, its picker, and the
 * contact column, which links the contact's page. The contact stays optional.
 */
final class PeopleContactTest extends WebTestCase
{
    private const string OWNER = 'owner@example.com';
    private const string MEMBER = 'member@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IdentityFixtures $fixtures;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->fixtures = new IdentityFixtures($this->em);
        $this->fixtures->rebuildSchema();
        $this->fixtures->join($this->fixtures->user(self::OWNER));
    }

    public function testAGuestIsInvitedWithTheContactTheyRepresent(): void
    {
        $contact = $this->contact();
        $this->signIn(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people/invite');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="contactId"]'));

        $this->client->submit($crawler->filter('form[action="/settings/people/invite"] button[type="submit"]')->form(['email' => 'alex@example.com', 'name' => 'Alex Example', 'role' => 'guest', 'contactId' => $contact, 'locale' => 'en']));

        self::assertResponseRedirects();
        $invitations = $this->em->getRepository(Invitation::class)->findAll();
        self::assertCount(1, $invitations);
        self::assertSame($contact, $invitations[0]->party()?->id());
        $crawler = $this->client->request('GET', '/settings/people?view=invitations');
        self::assertCount(1, $crawler->filter('tbody a[href="/contacts/'.$contact.'"]'));
    }

    public function testTheContactIsLinkedAndUnlinkedOnTheEditForm(): void
    {
        $this->fixtures->join($this->fixtures->user(self::MEMBER), Role::Member);
        $membership = $this->membershipOf(self::MEMBER);
        $contact = $this->contact();
        $this->signIn(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people/'.$membership.'/edit');
        self::assertCount(1, $crawler->filter('form#person-form input[name="contactId"]'));

        $this->client->submit($crawler->filter('form#person-form')->form(['role' => 'guest', 'contactId' => $contact]));
        self::assertResponseRedirects('/settings/people');
        self::assertSame($contact, $this->partyOf($membership));
        $crawler = $this->client->request('GET', '/settings/people');
        self::assertSelectorTextContains('thead', 'Contact');
        self::assertCount(1, $crawler->filter('tbody a[href="/contacts/'.$contact.'"]'));

        // An empty field unlinks the contact; the guest stays a guest.
        $crawler = $this->client->request('GET', '/settings/people/'.$membership.'/edit');
        $this->client->submit($crawler->filter('form#person-form')->form(['contactId' => '', 'contactQuery' => '']));
        self::assertResponseRedirects('/settings/people');
        self::assertNull($this->partyOf($membership));
        self::assertSame(Role::Guest, $this->em->find(Membership::class, $membership)?->role());
    }

    public function testAnUnknownContactIsRefused(): void
    {
        $this->fixtures->join($this->fixtures->user(self::MEMBER), Role::Member);
        $membership = $this->membershipOf(self::MEMBER);
        $this->signIn(self::OWNER);
        $crawler = $this->client->request('GET', '/settings/people/'.$membership.'/edit');

        $this->client->submit($crawler->filter('form#person-form')->form(['contactId' => '0190a0a0-0000-7000-8000-000000000000']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose a contact from the address book.');
    }

    public function testTheOwnerFindsContactsOfAnyKindAndOnlyTheOwner(): void
    {
        $contact = $this->contact();
        $this->fixtures->join($this->fixtures->user(self::MEMBER), Role::Member);
        $this->signIn(self::OWNER);

        $this->client->request('GET', '/settings/people/contacts?q=Alex');

        self::assertResponseIsSuccessful();
        self::assertSame(['results' => [['id' => $contact, 'label' => 'Alex Example', 'hint' => 'Person']]], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));

        $this->client->getCookieJar()->clear();
        $this->signIn(self::MEMBER);
        $this->client->request('GET', '/settings/people/contacts?q=Alex');
        self::assertResponseStatusCodeSame(403);
    }

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => $email, '_password' => IdentityFixtures::PASSWORD]));
    }

    private function contact(): string
    {
        $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $this->em->persist($party);
        $this->em->flush();

        return $party->id();
    }

    private function membershipOf(string $email): string
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT m.id FROM membership m JOIN app_user u ON u.id = m.user_id WHERE u.email = ?', [$email]);
        self::assertCount(1, $ids);
        self::assertIsString($ids[0]);

        return $ids[0];
    }

    private function partyOf(string $membership): ?string
    {
        $party = $this->em->getConnection()->fetchOne('SELECT party_id FROM membership WHERE id = ?', [$membership]);

        return \is_string($party) ? $party : null;
    }
}
