<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Contacts;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The new project dialog where the address book is installed: Timesheet adds
 * to it (Work's ProjectSlots::NEW_FORM) what makes the box "Bill time" follow
 * the client while nobody changed it by hand. What the dialog posts is what
 * the project keeps; ProjectTimeFieldDefaults decides only for a project
 * created without the field, as over the API.
 */
final class NewProjectBillableTest extends WebTestCase
{
    private const string BILLABLE = 'custom_fields[timesheet_billable_by_default]';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $browser = new SignedInBrowser($this->client, $this->em);
        $browser->seed();
        $browser->signIn();
    }

    public function testTheBoxFollowsTheClientComboboxOfTheForm(): void
    {
        $crawler = $this->client->request('GET', '/projects/new');

        self::assertResponseIsSuccessful();
        $follower = $crawler->filter('#project-form [data-controller="billable-default"]');
        self::assertCount(1, $follower);
        self::assertSame('client', $follower->attr('data-billable-default-client-value'));
        self::assertSame('timesheet_billable_by_default', $follower->attr('data-billable-default-field-value'));
        // What it reads: the client combobox, and the box inside the field's own wrapper, unticked until a client is picked.
        self::assertCount(1, $crawler->filter('#project-form [data-controller="combobox"] #client'));
        $box = $crawler->filter('#project-form [data-custom-field="timesheet_billable_by_default"] input[type="checkbox"]');
        self::assertCount(1, $box);
        self::assertNull($box->attr('checked'));
    }

    public function testTheProjectKeepsWhatTheBoxSaid(): void
    {
        $client = $this->contact('Example Client');

        // Ticked, as the box is once a client is picked.
        $this->create('Website', ['clientId' => $client, self::BILLABLE => true]);
        self::assertTrue($this->billable('Website'));

        // Unticked by hand: the default for a client does not tick it again.
        $this->create('Pro bono', ['clientId' => $client]);
        self::assertNull($this->billable('Pro bono'));
    }

    /**
     * @param array<string, string|bool> $values
     */
    private function create(string $name, array $values): void
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $form = $crawler->filter('#project-form')->form();
        $form['name'] = $name;
        $form['clientId'] = (string) $values['clientId'];
        $box = $form[self::BILLABLE];
        self::assertInstanceOf(ChoiceFormField::class, $box);
        true === ($values[self::BILLABLE] ?? false) ? $box->tick() : $box->untick();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);
    }

    private function billable(string $name): ?bool
    {
        $value = $this->em->getConnection()->fetchOne('SELECT f.billable_by_default FROM project p LEFT JOIN project_time_field f ON f.id = p.id WHERE p.name = ?', [$name]);

        return null === $value ? null : (bool) $value;
    }

    private function contact(string $name): string
    {
        $party = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($party);
        $this->em->flush();

        return $party->id();
    }
}
