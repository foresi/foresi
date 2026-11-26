<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Infrastructure;

use Foresi\Identity\Domain\User;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Inbox\Support\InboxMail;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /**
     * Each write of the item page, as its form posts it.
     *
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function writes(): iterable
    {
        yield 'done' => ['done', []];
        yield 'discard' => ['discard', []];
        yield 'reopen' => ['reopen', []];
        yield 'match again' => ['match-again', []];
        yield 'remove the match' => ['unmatch', []];
        yield 'match' => ['match', ['contactId' => '0192f1a4-0000-7000-8000-000000000001']];
        yield 'create a contact' => ['contact/new', ['kind' => 'person', 'displayName' => 'Client B', 'street' => 'Example Street 2', 'city' => 'Example City', 'postalCode' => '110 00', 'countryCode' => 'CZ']];
        yield 'link' => ['link', ['kind' => 'contact', 'recordId' => '0192f1a4-0000-7000-8000-000000000001']];
        yield 'an action' => ['actions/inbox.actions.fixture_note', ['text' => 'A note', 'noteKind' => 'mail', 'markDone' => '1']];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('writes')]
    public function testAnotherPersonCannotChangeAnItemWithAValidToken(string $action, array $fields): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        $colleague = $this->colleague();
        $token = $this->tokenOf($colleague);

        $this->client->request('POST', '/inbox/'.$id.'/'.$action, $fields + ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        $item = new DoctrineItemStore($this->em)->item($id);
        self::assertSame([ItemState::New, null], [$item?->state(), $item?->contactId()]);
        self::assertEquals(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_item_output'));
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('writes')]
    public function testAWriteWithABadTokenIsRefused(string $action, array $fields): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        if ('reopen' === $action) {
            $crawler = $this->client->request('GET', '/inbox/'.$id);
            $this->client->submit($crawler->filter('form[data-inbox-done]')->form());
        }

        $this->client->request('POST', '/inbox/'.$id.'/'.$action, $fields + ['_token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertEquals(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_item_output'));
    }

    public function testACraftedCursorStartsTheListAtTheTop(): void
    {
        $this->receive(InboxMail::plain($this->address, subject: 'Offer A'));

        $crawler = $this->client->request('GET', '/inbox?after=1%27%20OR%201=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Offer A', $crawler->filter('[data-inbox-list]')->text());
    }

    public function testTheCreateContactDialogOpensWhenTheMailFileIsGone(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        $messageId = $this->em->getConnection()->fetchOne('SELECT mail_message_id FROM inbox_item WHERE id = ?', [$id]);
        self::assertIsString($messageId);
        $this->em->getConnection()->executeStatement('UPDATE mail_message SET raw_sha256 = ? WHERE id = ?', [hash('sha256', 'another file'), $messageId]);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/contact/new');

        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $crawler->filter('#displayName')->attr('value'));
        $this->client->request('GET', '/inbox/'.$id.'/attachments/0');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A valid token of the item forms in the person's own session, from an item of their own.
     */
    private function tokenOf(User $person): string
    {
        $address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $person->id(), 'Inbox')->address;
        $raw = InboxMail::plain($address, messageId: 'own-'.Id::generate().'@client-a.example.com');
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive($raw, InboundChannel::Webhook, $address));
        $own = $this->em->getConnection()->fetchOne('SELECT i.id FROM inbox_item i WHERE i.owner_user_id = ?', [$person->id()]);
        self::assertIsString($own);
        $this->signInAs($person);
        $crawler = $this->client->request('GET', '/inbox/'.$own);
        self::assertResponseIsSuccessful();

        $token = (string) $crawler->filter('form[data-inbox-done] input[name="_token"]')->attr('value');
        self::assertNotSame('', $token);

        return $token;
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
