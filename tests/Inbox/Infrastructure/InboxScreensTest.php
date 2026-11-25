<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Infrastructure;

use Foresi\Tests\Inbox\Support\InboxMail;

/**
 * The inbox list and the item page: only the owner's items, mail text shown as
 * escaped text, attachments as downloads, replies grouped under one row.
 */
final class InboxScreensTest extends InboxScreenTestCase
{
    public function testTheInboxListsTheOwnersNewItemsAndTheNavigationCountsThem(): void
    {
        $this->receive(InboxMail::plain($this->address, subject: 'Offer A', messageId: 'a@client-a.example.com'));
        $this->receive(InboxMail::plain($this->address, subject: 'Offer B', messageId: 'b@client-a.example.com'));

        $crawler = $this->client->request('GET', '/inbox');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Offer A', $crawler->filter('[data-inbox-list]')->text());
        self::assertStringContainsString('Offer B', $crawler->filter('[data-inbox-list]')->text());
        $link = $crawler->filter('a[href="/inbox"]');
        self::assertGreaterThan(0, $link->count());
        self::assertStringContainsString('2', $link->first()->ancestors()->first()->text());
    }

    public function testThreadRepliesGroupUnderOneRow(): void
    {
        $this->receive(InboxMail::plain($this->address, subject: 'Offer', messageId: 'o1@client-a.example.com'));
        $this->receive(InboxMail::plain($this->address, subject: 'Re: Offer', messageId: 'o2@client-a.example.com', inReplyTo: 'o1@client-a.example.com', references: ['o1@client-a.example.com']));

        $crawler = $this->client->request('GET', '/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-inbox-list] tbody tr'));
        self::assertStringContainsString('Re: Offer (+1 more)', $crawler->filter('[data-inbox-list]')->text());
    }

    public function testAnItemIsVisibleOnlyToItsOwner(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        $this->signInAs($this->colleague());

        $this->client->request('GET', '/inbox/'.$id);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/inbox/'.$id.'/attachments/0');
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->client->request('GET', '/inbox');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-inbox-list] tbody tr'));
    }

    public function testHtmlIsNeverRendered(): void
    {
        $body = "Hi <script>alert(1)</script> <img src=\"http://tracker.example.com/p.gif\">\r\nSee https://evil.example.com/x?a=1 now.\r\n";
        $id = $this->receive(InboxMail::plain($this->address, body: $body));

        $crawler = $this->client->request('GET', '/inbox/'.$id);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('[data-inbox-text]');
        self::assertStringContainsString('<script>alert(1)</script>', $text->text());
        self::assertStringContainsString('<img src="http://tracker.example.com/p.gif', $text->text());
        self::assertCount(0, $crawler->filter('img[src^="http://tracker"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-text] script, [data-inbox-text] img, [data-inbox-text] a'));
        self::assertStringContainsString('https://evil.example.com/x?a=1 [evil.example.com]', $text->text());
        self::assertStringNotContainsString('<script>alert', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnverifiedSenderIsWarnedAbout(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $crawler = $this->client->request('GET', '/inbox/'.$id);

        self::assertCount(1, $crawler->filter('[data-inbox-warning="sender_not_verified"]'));
    }

    public function testAttachmentsAreServedAsDownloadsOnly(): void
    {
        $raw = $this->withAttachment('..\\evil"name.html', '<html><script>alert(1)</script></html>');
        $id = $this->receive($raw);

        $crawler = $this->client->request('GET', '/inbox/'.$id);
        self::assertCount(1, $crawler->filter('[data-inbox-attachments] a[download]'));
        $this->client->request('GET', '/inbox/'.$id.'/attachments/0');

        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertSame('application/octet-stream', $headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string) $headers->get('Content-Disposition'));
        self::assertStringNotContainsString('..', (string) $headers->get('Content-Disposition'));
        self::assertStringNotContainsString('\\', (string) $headers->get('Content-Disposition'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('<script>', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/inbox/'.$id.'/attachments/5');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDoneAndReopenMoveTheItemBetweenTheViews(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        $crawler = $this->client->request('GET', '/inbox/'.$id);
        $this->client->submit($crawler->filter('form[data-inbox-done]')->form());
        self::assertResponseRedirects('/inbox');

        $crawler = $this->client->request('GET', '/inbox?view=done');
        self::assertCount(1, $crawler->filter('[data-inbox-list] tbody tr'));
        $crawler = $this->client->request('GET', '/inbox');
        self::assertCount(0, $crawler->filter('[data-inbox-list] tbody tr'));

        $crawler = $this->client->request('GET', '/inbox/'.$id);
        $this->client->submit($crawler->filter('form[data-inbox-reopen]')->form());
        self::assertResponseRedirects('/inbox/'.$id);
    }

    public function testTheCreateContactDialogStartsFromTheSender(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/contact/new');

        self::assertResponseIsSuccessful();
        self::assertSame('Client A', $crawler->filter('#displayName')->attr('value'));
        self::assertSame('client.a@client-a.example.com', $crawler->filter('#email')->attr('value'));
    }

    private function withAttachment(string $name, string $content): string
    {
        $mail = InboxMail::plain($this->address, messageId: 'att@client-a.example.com', headers: []);
        [$head] = explode("\r\n\r\n", $mail, 2);
        $head = preg_replace('/^Content-Type:.*\r\n|^Content-Transfer-Encoding:.*\r\n/m', '', $head);

        return $head."\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSee the file.\r\n"
            ."--b1\r\nContent-Type: text/html; name=\"x.html\"\r\nContent-Disposition: attachment; filename=\"".addcslashes($name, '"')."\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($content))."--b1--\r\n";
    }
}
