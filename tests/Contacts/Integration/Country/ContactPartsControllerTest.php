<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Integration\Country;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Contacts\Application\CreateParty;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The tabs of a contact add their contact persons, bank accounts and addresses
 * in dialogs, which are pages too, and change them with row actions.
 */
final class ContactPartsControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testADomesticAccountNumberIsStoredAsAnIbanAndTheFirstIsPrimary(): void
    {
        $this->signIn();
        $company = $this->party('Example Company');

        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/bank-accounts/new');
        $this->client->submit($crawler->filter('#contact-account-form')->form(['number' => '19-2000145399/0800', 'bic' => 'gibaczpx', 'label' => 'Main']));
        self::assertResponseRedirects('/contacts/'.$company->id().'?tab=bank-accounts');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', 'CZ65 0800 0000 1920 0014 5399');
        self::assertSelectorTextContains('main table', 'GIBACZPX');
        self::assertSelectorTextContains('main table', 'Primary');

        // The same account again, or a number that is none, is refused at the field.
        foreach ([self::IBAN => 'The contact has this account already.', '123' => 'Enter an IBAN or a domestic account number with a bank code.'] as $number => $message) {
            $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/bank-accounts/new');
            $this->client->submit($crawler->filter('#contact-account-form')->form(['number' => (string) $number]));
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('#number-error', $message);
        }
        self::assertSame(1, $this->rows(PartyBankAccount::class));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed('en', $role);
        $this->browser->signIn();
    }

    private function party(string $name, PartyKind $kind = PartyKind::SoleTrader): Party
    {
        // The fixture is set up by the application, not by the signed-in person.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        $party = self::getContainer()->get(CreateParty::class)->create($kind, $name, null, null, '', '', PartyKind::Person === $kind ? [] : [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $tokens->setToken($signedIn);

        return $party;
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(e.id) FROM '.$class.' e')->getSingleScalarResult();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
