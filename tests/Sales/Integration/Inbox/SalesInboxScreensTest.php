<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Integration\Inbox;

use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Tests\Inbox\Infrastructure\InboxScreenTestCase;
use Foresi\Tests\Inbox\Support\InboxMail;

/**
 * Create deal on the page of an inbox item matched to a contact: the client is
 * picked by name, never typed as an id.
 */
final class SalesInboxScreensTest extends InboxScreenTestCase
{
    public function testTheDialogPicksTheClientByName(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, subject: 'Roof audit'));
        $page = $this->client->request('GET', '/inbox/'.$id);
        $token = (string) $page->filter('form[action^="/inbox/"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/inbox/'.$id.'/contact/new', ['_token' => $token, 'kind' => 'person', 'displayName' => 'Client A', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '110 00', 'countryCode' => 'CZ', 'email' => 'client.a@client-a.example.com']);
        self::assertResponseRedirects('/inbox/'.$id);
        $this->em->clear();
        $contactId = new DoctrineItemStore($this->em)->item($id)?->contactId();
        self::assertIsString($contactId);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/inbox.actions.create_deal');

        self::assertResponseIsSuccessful();
        $client = $crawler->filter('select#inbox-action-client_party_id option[selected]');
        self::assertSame([$contactId, 'Client A'], [$client->attr('value'), $client->text()]);
        self::assertSame('Roof audit', $crawler->filter('#inbox-action-title')->attr('value'));
    }
}
