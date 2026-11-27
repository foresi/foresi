<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Inbox;

use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Tests\Inbox\Infrastructure\InboxScreenTestCase;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Work\Application\ManageProjects;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Create task on the page of an inbox item: the button in the header, the dialog
 * filled from the mail with the client's one project chosen, and the task added
 * to the item, which is marked done.
 */
final class WorkInboxScreensTest extends InboxScreenTestCase
{
    private const string CREATE_TASK = 'inbox.actions.create_task';

    public function testTheDialogIsFilledFromTheMailWithTheSubjectAsTheTitle(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, subject: 'Offer for the new office', body: "Please send the offer by Friday.\r\n"));
        $clientId = $this->createContact($id);
        $project = $this->project('Office move', $clientId);

        $page = $this->client->request('GET', '/inbox/'.$id);
        self::assertSame('/inbox/'.$id.'/actions/'.self::CREATE_TASK, $page->filter('[data-inbox-action="'.self::CREATE_TASK.'"]')->attr('href'));
        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::CREATE_TASK);

        self::assertResponseIsSuccessful();
        self::assertSame('Offer for the new office', $crawler->filter('#inbox-action-title')->attr('value'));
        self::assertSame('Please send the offer by Friday.', $crawler->filter('textarea#inbox-action-description')->text());
        self::assertSame($project, $crawler->filter('#inbox-action-project_id option[selected]')->attr('value'), 'The client\'s one project is chosen.');
        self::assertSame('date', $crawler->filter('#inbox-action-due_on')->attr('type'));
        self::assertNotNull($crawler->filter('#inbox-action-mark_done')->attr('checked'));
    }

    public function testSavingCreatesTheTaskAddsItAsAnOutputAndMarksTheItemDone(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        $clientId = $this->createContact($id);
        $project = $this->project('Office move', $clientId);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::CREATE_TASK);
        $this->client->submit($crawler->filter('#inbox-action-form')->form());

        self::assertResponseRedirects('/inbox/'.$id);
        $task = $this->em->getConnection()->fetchAssociative('SELECT id, title, project_id FROM task');
        self::assertIsArray($task);
        self::assertSame(['Offer for the new office', $project], [$task['title'], $task['project_id']]);
        $this->em->clear();
        $item = new DoctrineItemStore($this->em)->item($id);
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(ItemState::Done, $item->state());
        $page = $this->client->followRedirect();
        $output = $page->filter('[data-inbox-outputs] a');
        self::assertIsString($task['id']);
        self::assertSame(['Offer for the new office', '/tasks/'.$task['id']], [$output->text(), $output->attr('href')]);
    }

    public function testAProjectLeftOutShowsOnItsField(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        $this->project('Office move', null);
        $this->project('Website', null);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::CREATE_TASK);
        self::assertSame('', $crawler->filter('#inbox-action-project_id option[selected]')->attr('value'), 'Two projects and no client: none is chosen.');
        $crawler = $this->client->submit($crawler->filter('#inbox-action-form')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Fill this in.', $crawler->filter('#inbox-action-project_id-error')->text());
        self::assertEquals(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task'));
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
