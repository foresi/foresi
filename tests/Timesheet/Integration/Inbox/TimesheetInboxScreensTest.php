<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Inbox;

use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Tests\Inbox\Infrastructure\InboxScreenTestCase;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Work\Application\ManageProjects;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The dialog of an inbox action of Timesheet on the item's page: time logged
 * from an e-mail, its billable left to the project unless chosen.
 */
final class TimesheetInboxScreensTest extends InboxScreenTestCase
{
    public function testTheLogTimeDialogTakesANumberADateAndTimeAndABillableLeftToTheProject(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        $clientId = $this->createContact($id);
        $project = $this->project('Office move', $clientId);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/inbox.actions.log_time');
        self::assertResponseIsSuccessful();
        self::assertSame(['number', 'datetime-local', 'date'], [$crawler->filter('#inbox-action-minutes')->attr('type'), $crawler->filter('#inbox-action-started_at')->attr('type'), $crawler->filter('#inbox-action-worked_on')->attr('type')]);
        self::assertSame(['', '1', '0'], $crawler->filter('#inbox-action-billable option')->each(static fn ($option): string => (string) $option->attr('value')));
        $form = $crawler->filter('#inbox-action-form')->form();
        $form['minutes'] = '45';
        $form['billable'] = '0';
        $this->client->submit($form);

        self::assertResponseRedirects('/inbox/'.$id);
        $entry = $this->em->getConnection()->fetchAssociative('SELECT minutes, billable, project_id FROM time_entry');
        self::assertIsArray($entry);
        self::assertEquals([45, false, $project], [$entry['minutes'], (bool) $entry['billable'], $entry['project_id']]);
    }

    private function createContact(string $id): string
    {
        $page = $this->client->request('GET', '/inbox/'.$id);
        $token = (string) $page->filter('form[action^="/inbox/"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/inbox/'.$id.'/contact/new', ['_token' => $token, 'kind' => 'person', 'displayName' => 'Client A', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '110 00', 'countryCode' => 'CZ', 'email' => 'client.a@client-a.example.com']);
        self::assertResponseRedirects('/inbox/'.$id);
        $this->em->clear();
        $contactId = new DoctrineItemStore($this->em)->item($id)?->contactId();
        self::assertIsString($contactId);

        return $contactId;
    }

    private function project(string $name, ?string $clientId): string
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $tokens->setToken(new UsernamePasswordToken(new SecurityUser($this->owner->id(), $this->owner->email(), $this->owner->passwordHash()), 'main', ['ROLE_USER']));
        try {
            $project = self::getContainer()->get(ManageProjects::class)->create($name, $clientId);
            $this->em->flush();
        } finally {
            $tokens->setToken(null);
        }

        return $project->id();
    }
}
