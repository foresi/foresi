<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Country;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Infrastructure\Support\RejectingParties;
use Foresi\Tests\Support\Registry\RegistryCalls;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PartyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function tearDown(): void
    {
        RejectingParties::$reject = false;
        RegistryCalls::reset();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testInvalidSubmissionShowsErrorsAndKeepsTheEnteredValues(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/contacts/new');
        $form = $crawler->filter('main form')->form($this->values(['displayName' => '', 'registrationNumber' => '12345670', 'vatId' => 'CZ12', 'city' => 'Entered City']));

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#displayName-error', 'Fill in this field.');
        self::assertSelectorTextContains('#registrationNumber-error', 'Invalid registration number');
        self::assertSelectorTextContains('#vatId-error', 'Invalid VAT ID');
        self::assertSame('Entered City', $this->client->getCrawler()->filter('#city')->attr('value'));
        self::assertSame(0, $this->partyCount());
    }

    private function signIn(string $locale = 'en', Role $role = Role::Owner): void
    {
        $this->browser->seed($locale, $role);
        $this->browser->signIn();
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function values(array $overrides = []): array
    {
        return $overrides + ['kind' => 'sole_trader', 'displayName' => 'Example Company s.r.o.', 'registrationCountry' => 'CZ', 'registrationNumber' => '', 'vatId' => '', 'email' => '', 'phone' => '', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '100 00', 'countryCode' => 'CZ', 'privateNote' => ''];
    }

    private function partyCount(): int
    {
        return (int) self::getContainer()->get(EntityManagerInterface::class)->createQuery('SELECT COUNT(p.id) FROM '.Party::class.' p')->getSingleScalarResult();
    }
}
