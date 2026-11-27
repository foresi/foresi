<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Integration\Contacts;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\Stage;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Deals tab of a contact: the client's deals with the chain of follow-ups,
 * "follows from …" under a deal that follows another, and New deal for the
 * client.
 */
final class DealsContactTabTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, $this->em());
        $browser->seed('en');
        $this->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->em()->persist(new Stage('inquiry', null, 0, new \DateTimeImmutable('2026-11-01 10:00:00')));
        $this->em()->flush();
        $browser->signIn();
    }

    public function testClientTabShowsChain(): void
    {
        $company = new Party(PartyKind::Company, 'Alpha Example s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '', [PartyRole::Customer]);
        $this->em()->persist($company);
        $this->em()->flush();
        $audit = $this->newDeal($company, 'Roof audit');
        $token = (string) $this->client->request('GET', '/deals/new')->filter('#deal-form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/deals/'.$audit->id().'/follow-up', ['_token' => $token, 'title' => 'Roof maintenance']);
        self::assertResponseRedirects();

        // The contact offers the tab, which loads the Sales fragment.
        $crawler = $this->client->request('GET', '/contacts/'.$company->id());
        self::assertSame('Deals', trim($crawler->filter('a[href="/contacts/'.$company->id().'?tab=deals"]')->text()));
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'?tab=deals');
        self::assertSame('/deals/clients/'.$company->id().'/deals', $crawler->filter('turbo-frame#contact-tab')->attr('src'));

        $crawler = $this->client->request('GET', '/deals/clients/'.$company->id().'/deals', server: ['HTTP_TURBO_FRAME' => 'contact-tab']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#contact-tab'));
        self::assertCount(2, $crawler->filter('[data-client-deal]'));
        $followUp = $crawler->filter('[data-client-deal]')->reduce(static fn ($item): bool => str_contains($item->text(), 'Roof maintenance'));
        self::assertSame('Follows from Roof audit', trim((string) preg_replace('/\s+/', ' ', $followUp->filter('[data-follows-from]')->text())));
        self::assertSame('/deals/'.$audit->id(), $followUp->filter('[data-follows-from] a')->attr('href'));
        self::assertSame('/deals/new?client='.$company->id(), $crawler->filter('[data-client-deal-new]')->attr('href'));
    }

    private function newDeal(Party $company, string $title): Deal
    {
        $form = $this->client->request('GET', '/deals/new?client='.$company->id())->filter('#deal-form')->form();
        $form['title'] = $title;
        $this->client->submit($form);
        self::assertResponseRedirects();
        $deal = $this->em()->getRepository(Deal::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Deal::class, $deal);

        return $deal;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
