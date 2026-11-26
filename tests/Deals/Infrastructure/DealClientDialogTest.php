<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Infrastructure;

use Foresi\Deals\Application\Api\AddDealLinkInput;
use Foresi\Deals\Application\DealLinkWrites;
use Foresi\Deals\Application\DealReads;
use Foresi\Deals\Domain\Deal;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Tests\Deals\Support\SalesScreenTestCase;

/**
 * The client and its contact persons in the deal's dialogs: New from the list offers the
 * persons once a client is chosen, Edit shows the client and changes it (read-only while
 * the deal is linked), the persons come from a fragment when the client changes, and a
 * person is added to the client from inside the dialog.
 */
final class DealClientDialogTest extends SalesScreenTestCase
{
    private Party $beta;
    private Party $jana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beta = new Party(PartyKind::Company, 'Beta Example a.s.', new RegistrationNumber('CZ', '12345679'), null, '', [PartyRole::Customer]);
        $this->jana = new Party(PartyKind::Person, 'Jana Example', null, null, '');
        $this->em()->persist($this->beta);
        $this->em()->persist($this->jana);
        $this->em()->flush();
    }

    public function testTheFragmentShowsTheClientsPersonsAndAPersonToAdd(): void
    {
        $crawler = $this->client->request('GET', '/sales/deals/client-persons?client='.$this->company->id());
        self::assertResponseIsSuccessful();
        self::assertSame([$this->petra->id(), $this->karel->id()], $crawler->filter('input[name="contactPersonIds[]"]')->each(static fn ($input): string => (string) $input->attr('value')));
        self::assertCount(0, $crawler->filter('input[name="contactPersonIds[]"][checked]'), 'A new client starts with no person ticked.');
        self::assertCount(1, $crawler->filter('details[data-deal-add-person]:not([open])'));
        // "Add a person" is a plain action right under the last person, in the persons' own fieldset.
        self::assertSame('Add a person', trim($crawler->filter('fieldset[data-deal-persons] > details[data-deal-add-person] > summary')->text()));
        self::assertSame('details', $crawler->filter('fieldset[data-deal-persons] > *')->last()->nodeName());
        // The candidates: persons of the address book the client does not list yet.
        self::assertSame(['', $this->jana->id()], $crawler->filter('#personId option')->each(static fn ($option): string => (string) $option->attr('value')));
        self::assertSame('existing', $crawler->filter('input[name="personMode"][checked]')->attr('value'));

        // A client with no contact persons says so, and still offers to add one.
        $crawler = $this->client->request('GET', '/sales/deals/client-persons?client='.$this->beta->id());
        self::assertCount(1, $crawler->filter('[data-deal-no-persons]'));
        self::assertCount(1, $crawler->filter('[data-deal-add-person]'));

        // A person as the client, or no client known, has no block at all.
        foreach ([$this->petra->id(), '0192f1a4-0000-7000-8000-000000000000', ''] as $client) {
            $this->client->request('GET', '/sales/deals/client-persons?client='.$client);
            self::assertResponseIsSuccessful();
            self::assertSame('', trim((string) $this->client->getResponse()->getContent()), $client);
        }
    }

    public function testANewDealFromTheListOffersThePersonsOnceTheClientIsKnown(): void
    {
        $crawler = $this->client->request('GET', '/sales/deals/new', server: [self::DIALOG => '1']);
        self::assertCount(1, $crawler->filter('#deal-form [data-controller="combobox"] input[name="clientId"]'));
        self::assertCount(0, $crawler->filter('#deal-form [data-deal-persons]'));
        self::assertSame('/sales/deals/client-persons', $crawler->filter('#deal-form')->attr('data-deal-client-url-value'));

        // Without JavaScript a refused form comes back with the persons of the client sent.
        $values = $crawler->filter('#deal-form')->form()->getPhpValues();
        $crawler = $this->client->request('POST', '/sales/deals/new', ['title' => '', 'clientId' => $this->company->id(), 'clientQuery' => 'Alpha Example s.r.o.', 'contactPersonIds' => [$this->karel->id()]] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($this->company->id(), $crawler->filter('#deal-form input[name="clientId"]')->attr('value'));
        self::assertSame([$this->karel->id()], $crawler->filter('input[name="contactPersonIds[]"][checked]')->each(static fn ($input): string => (string) $input->attr('value')));
        self::assertCount(2, $crawler->filter('input[name="contactPersonIds[]"]'));
    }

    public function testAPersonIsAddedToTheClientFromTheNewDialog(): void
    {
        $values = $this->client->request('GET', '/sales/deals/new?client='.$this->company->id())->filter('#deal-form')->form()->getPhpValues();

        // A refused person stays in the open "Add a person", with the message at its field, and nothing is stored.
        $crawler = $this->client->request('POST', '/sales/deals/new', ['title' => 'Roof audit', 'personMode' => 'new', 'personName' => '', 'personEmail' => 'not-an-address'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('details[data-deal-add-person][open]'));
        self::assertSelectorTextContains('#deal-form', 'Fill in this field.');
        self::assertSelectorTextContains('#deal-form', 'Enter a valid e-mail address.');
        self::assertSame('new', $crawler->filter('input[name="personMode"][checked]')->attr('value'));
        self::assertSame('not-an-address', $crawler->filter('#personEmail')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-deal-client]'), 'The client stays fixed.');
        self::assertCount(0, $this->em()->getRepository(Deal::class)->findAll());

        $this->client->request('POST', '/sales/deals/new', ['title' => 'Roof audit', 'contactPersonIds' => [$this->petra->id()], 'personMode' => 'new', 'personName' => 'Eva Example', 'personEmail' => 'eva@example.com', 'personPosition' => 'Buyer'] + $values);
        self::assertResponseRedirects();
        $deal = $this->deal('Roof audit');
        $eva = $this->personOf($this->company, 'Eva Example');
        self::assertSame(['eva@example.com', 'Buyer'], [$eva->email, $eva->position]);
        self::assertSame([$this->petra->id(), $eva->id], $this->contacts($deal));
    }

    public function testTheEditDialogShowsTheClientAndChangesIt(): void
    {
        $deal = $this->newDeal('Roof audit', [0]);

        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id().'/edit', server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame($this->company->id(), $crawler->filter('#deal-form input[name="clientId"]')->attr('value'));
        self::assertSame('Alpha Example s.r.o.', $crawler->filter('#client')->attr('value'));
        self::assertSame([$this->petra->id()], $crawler->filter('input[name="contactPersonIds[]"][checked]')->each(static fn ($input): string => (string) $input->attr('value')));

        // Another client and a person of the address book added to it, in one go; the persons of the old client go.
        $values = $crawler->filter('#deal-form')->form()->getPhpValues();
        unset($values['contactPersonIds']);
        $this->client->request('POST', '/sales/deals/'.$deal->id().'/edit', ['clientId' => $this->beta->id(), 'personMode' => 'existing', 'personId' => $this->jana->id(), 'personPosition' => 'Director'] + $values);
        self::assertResponseRedirects('/sales/deals/'.$deal->id());
        self::assertSame($this->beta->id(), $this->deal('Roof audit')->clientPartyId());
        self::assertSame([$this->jana->id()], $this->contacts($deal));
        self::assertSame('Director', $this->personOf($this->beta, 'Jana Example')->position);

        // An emptied client is refused at its field.
        $this->client->request('POST', '/sales/deals/'.$deal->id().'/edit', ['clientId' => '', 'clientQuery' => 'Bet'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#deal-form', 'Choose a contact of the address book.');
        self::assertSame($this->beta->id(), $this->deal('Roof audit')->clientPartyId());
    }

    public function testALinkedDealShowsItsClientReadOnly(): void
    {
        $audit = $this->newDeal('Roof audit');
        $repair = $this->newDeal('Gutter repair', [1]);
        $this->actAsOwner();
        self::getContainer()->get(DealLinkWrites::class)->add(new AddDealLinkInput($repair->id(), $audit->id()));

        $crawler = $this->client->request('GET', '/sales/deals/'.$repair->id().'/edit', server: [self::DIALOG => '1']);
        self::assertSelectorTextContains('[data-deal-client]', 'Alpha Example s.r.o.');
        self::assertSame('/contacts/'.$this->company->id(), $crawler->filter('[data-deal-client] a[data-deal-client-link]')->attr('href'), 'The client is read-only text, its name a link to the contact.');
        self::assertSelectorTextContains('[data-deal-client-linked]', 'Remove the links to other deals to change the client');
        self::assertCount(0, $crawler->filter('#deal-form input[name="clientId"]'));

        // A client sent anyway is ignored, and the rest is saved.
        $values = $crawler->filter('#deal-form')->form()->getPhpValues();
        $this->client->request('POST', '/sales/deals/'.$repair->id().'/edit', ['title' => 'Gutter repair, phase 2', 'clientId' => $this->beta->id()] + $values);
        self::assertResponseRedirects();
        $kept = $this->deal('Gutter repair, phase 2');
        self::assertSame($this->company->id(), $kept->clientPartyId());
        self::assertSame([$this->karel->id()], $this->contacts($kept));
    }

    private function deal(string $title): Deal
    {
        $this->em()->clear();
        $deal = $this->em()->getRepository(Deal::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Deal::class, $deal);

        return $deal;
    }

    /**
     * @return list<string>
     */
    private function contacts(Deal $deal): array
    {
        $this->actAsOwner();

        return self::getContainer()->get(DealReads::class)->deal($deal->id())->contactPersonIds;
    }

    private function personOf(Party $company, string $name): PartyPersonSummary
    {
        $this->actAsOwner();
        $found = array_values(array_filter(self::getContainer()->get(PartyDirectory::class)->personsOf($company->id()), static fn (PartyPersonSummary $person): bool => $name === $person->displayName));
        self::assertCount(1, $found);

        return $found[0];
    }
}
