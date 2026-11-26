<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Support;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\Stage;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Application\LinkContactPerson;
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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The screens of Sales with an owner signed in, the firm of the instance, one
 * stage, and a client company with two contact persons, for the tests of what
 * other modules add to a deal's page.
 */
abstract class SalesScreenTestCase extends WebTestCase
{
    protected const string DIALOG = 'HTTP_X_FORESI_DIALOG';

    protected KernelBrowser $client;
    protected SignedInBrowser $browser;
    protected User $user;
    protected Stage $stage;
    protected Party $company;
    protected Party $petra;
    protected Party $karel;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, $this->em());
        $this->user = $this->browser->seed('en');
        $this->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->em()->persist($this->stage = new Stage('proposal', null, 0, new \DateTimeImmutable('2026-11-01 10:00:00')));
        $this->company = new Party(PartyKind::Company, 'Alpha Example s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '', [PartyRole::Customer]);
        $this->petra = new Party(PartyKind::Person, 'Petra Example', null, null, '');
        $this->karel = new Party(PartyKind::Person, 'Karel Example', null, null, '');
        foreach ([$this->company, $this->petra, $this->karel] as $party) {
            $this->em()->persist($party);
        }
        $this->em()->flush();
        self::getContainer()->get(LinkContactPerson::class)->link($this->company, $this->petra, '', false);
        self::getContainer()->get(LinkContactPerson::class)->link($this->company, $this->karel, '', false);
        $this->browser->signIn();
    }

    /**
     * A deal of the company made through its dialog, with the contact persons ticked by their position.
     *
     * @param list<int> $persons
     */
    protected function newDeal(string $title, array $persons = []): Deal
    {
        $form = $this->client->request('GET', '/sales/deals/new?client='.$this->company->id())->filter('#deal-form')->form();
        $form['title'] = $title;
        $values = $form->getPhpValues();
        $ids = [$this->petra->id(), $this->karel->id()];
        $values['contactPersonIds'] = array_map(static fn (int $position): string => $ids[$position], $persons);
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        $this->em()->clear();
        $deal = $this->em()->getRepository(Deal::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Deal::class, $deal);

        return $deal;
    }

    /** The owner signed in for the services called outside a request. */
    protected function actAsOwner(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($this->user->id(), $this->user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
