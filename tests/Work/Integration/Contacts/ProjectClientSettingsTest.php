<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Contacts;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The client in the general part of a project's settings, where the address book is installed.
 */
final class ProjectClientSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->browser = new SignedInBrowser($this->client, $this->em);
    }

    public function testTheGeneralPartSavesTheNameTheClientAndTheDescription(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $client = $this->contact('Example Client');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');

        $crawler = $this->client->submit($crawler->filter('#project-settings-form')->form(['name' => ' ', 'description' => 'Kept.']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the name of the project.', trim($crawler->filter('#name-error')->text()));
        self::assertSame('Kept.', $crawler->filter('#description')->text());

        $crawler = $this->client->submit($crawler->filter('#project-settings-form')->form(['name' => 'Website', 'clientId' => '01900000-0000-7000-8000-000000000000']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Choose an existing contact as the client.', trim($crawler->filter('#client-error')->text()));

        $this->client->submit($crawler->filter('#project-settings-form')->form(['name' => 'New website', 'clientId' => $client, 'description' => 'The new site.']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Saved.');
        self::assertSame('Example Client', $crawler->filter('#client')->attr('value'));
        self::assertSame(['name' => 'New website', 'client_id' => $client, 'description' => 'The new site.'], $this->em->getConnection()->fetchAssociative('SELECT name, client_id, description FROM project WHERE id = ?', [$id]));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }

    private function createProject(string $name): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => $name]));
        self::assertResponseStatusCodeSame(303);
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }

    private function contact(string $name): string
    {
        $party = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($party);
        $this->em->flush();

        return $party->id();
    }
}
