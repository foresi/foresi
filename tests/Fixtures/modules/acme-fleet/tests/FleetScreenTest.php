<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Tests;

use Acme\Fleet\Domain\Vehicle;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The fleet's screen in the Ui shell and its navigation item, for the staff only.
 */
final class FleetScreenTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testAMemberSeesTheVehiclesInTheShell(): void
    {
        $this->signIn(Role::Member);
        $em = $this->browser->em();
        $em->persist(new Vehicle('0192f1a4-0000-7000-8000-000000000001', 'Delivery van', '1AB 2345', null, new \DateTimeImmutable()));
        $em->flush();

        $crawler = $this->client->request('GET', '/acme-fleet/vehicles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vehicles');
        self::assertSelectorTextContains('#acme-fleet-vehicles', '1AB 2345');
        self::assertCount(1, $crawler->filter('nav a[href="/acme-fleet/vehicles"]'));
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/acme-fleet/vehicles');

        self::assertResponseStatusCodeSame(403);
    }

    private function signIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        // A firm, so the instance is past its first-run setup.
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
        $this->browser->signIn();
    }
}
