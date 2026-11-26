<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Integration\Inbox;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Sales\Domain\Stage;
use Foresi\Tests\Inbox\Infrastructure\InboxScreenTestCase;
use Foresi\Tests\Inbox\Support\InboxMail;

/**
 * Create deal on the page of an inbox item matched to a contact: the client is
 * picked by name, never typed as an id, and saving opens the deal on the item.
 */
final class SalesInboxScreensTest extends InboxScreenTestCase
{
    public function testTheDialogPicksTheClientByName(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, subject: 'Roof audit'));
        $contactId = $this->createContact($id);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/inbox.actions.create_deal');

        self::assertResponseIsSuccessful();
        $client = $crawler->filter('select#inbox-action-client_party_id option[selected]');
        self::assertSame([$contactId, 'Client A'], [$client->attr('value'), $client->text()]);
        self::assertSame('Roof audit', $crawler->filter('#inbox-action-title')->attr('value'));
    }

    public function testSavingOpensTheDealAddsItAsAnOutputAndMarksTheItemDone(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, subject: 'Roof audit'));
        $contactId = $this->createContact($id);
        $this->em->persist(new Stage('inquiry', null, 0, new \DateTimeImmutable('2026-11-01 10:00:00')));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/inbox.actions.create_deal');
        $this->client->submit($crawler->filter('#inbox-action-form')->form());

        self::assertResponseRedirects('/inbox/'.$id);
        $deal = $this->em->getConnection()->fetchAssociative('SELECT id, title, client_party_id FROM sales_deal');
        self::assertIsArray($deal);
        self::assertSame(['Roof audit', $contactId], [$deal['title'], $deal['client_party_id']]);
        $this->em->clear();
        $item = new DoctrineItemStore($this->em)->item($id);
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(ItemState::Done, $item->state());
        $page = $this->client->followRedirect();
        self::assertIsString($deal['id']);
        self::assertSame('Roof audit', $page->filter('[data-inbox-outputs] a')->text());
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
}
