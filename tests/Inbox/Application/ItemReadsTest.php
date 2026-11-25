<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\Api\LinkItemInput;
use Foresi\Inbox\Application\ItemReads;
use Foresi\Inbox\Application\ItemRow;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\InboxTestCase;

/**
 * The person's inbox as a list: pages of at least one item, who has more behind them, and what
 * each row says of its conversation and its outputs.
 */
final class ItemReadsTest extends InboxTestCase
{
    public function testAPageHoldsAtLeastOneItemWhateverTheLimitAsksFor(): void
    {
        $this->receive(InboxMail::plain($this->address, messageId: 'a-1@client-a.example.com'));
        $this->receive(InboxMail::plain($this->address, messageId: 'a-2@client-a.example.com', subject: 'Second'));

        $slice = $this->reads()->list(null, false, null, 0);

        self::assertCount(1, $slice->rows);
        self::assertTrue($slice->hasMore);
        self::assertNotNull($slice->nextAfter());
    }

    public function testThereIsNoMoreAfterThePageThatHoldsTheLastItem(): void
    {
        $this->receive(InboxMail::plain($this->address, messageId: 'a-1@client-a.example.com'));
        $this->receive(InboxMail::plain($this->address, messageId: 'a-2@client-a.example.com', subject: 'Second'));

        $exact = $this->reads()->list(null, false, null, 2);
        $short = $this->reads()->list(null, false, null, 1);

        self::assertCount(2, $exact->rows);
        self::assertFalse($exact->hasMore, 'Exactly the limit: nothing follows.');
        self::assertNull($exact->nextAfter());
        self::assertTrue($short->hasMore);
        $rest = $this->reads()->list(null, false, $short->nextAfter(), 1);
        self::assertCount(1, $rest->rows);
        self::assertFalse($rest->hasMore);
        self::assertNotSame($short->rows[0]->item->id(), $rest->rows[0]->item->id());
    }

    public function testARowCountsTheOtherItemsOfItsConversationAndItsOutputs(): void
    {
        $alone = $this->receive(InboxMail::plain($this->address, messageId: 'alone@client-a.example.com', subject: 'Alone'));
        $root = $this->receive(InboxMail::plain($this->address, messageId: 'root@client-a.example.com', subject: 'Offer'));
        $reply = $this->receive(InboxMail::plain($this->address, messageId: 'reply@client-a.example.com', subject: 'Re: Offer', inReplyTo: 'root@client-a.example.com', references: ['root@client-a.example.com']));
        $third = $this->receive(InboxMail::plain($this->address, messageId: 'third@client-a.example.com', subject: 'Re: Re: Offer', inReplyTo: 'reply@client-a.example.com', references: ['root@client-a.example.com', 'reply@client-a.example.com']));
        $this->writes()->link(new LinkItemInput($root, 'contact', $this->contact('person', 'Client C', 'client.c@client-c.example.com')));
        $this->writes()->link(new LinkItemInput($root, 'contact', $this->contact('person', 'Client D', 'client.d@client-d.example.com')));

        $rows = [];
        foreach ($this->reads()->list(null, false, null, 50)->rows as $row) {
            $rows[$row->item->id()] = $row;
        }

        self::assertSame([0, 0], $this->counts($rows[$alone]));
        self::assertSame([2, 2], $this->counts($rows[$root]));
        self::assertSame([2, 0], $this->counts($rows[$reply]));
        self::assertSame([2, 0], $this->counts($rows[$third]));
    }

    public function testAnItemIsReadWithTheOtherMailsOfItsConversation(): void
    {
        $root = $this->receive(InboxMail::plain($this->address, messageId: 'root@client-a.example.com', subject: 'Offer'));
        $reply = $this->receive(InboxMail::plain($this->address, messageId: 'reply@client-a.example.com', subject: 'Re: Offer', inReplyTo: 'root@client-a.example.com', references: ['root@client-a.example.com']));
        $this->receive(InboxMail::plain($this->address, messageId: 'other@client-a.example.com', subject: 'Other'));

        $detail = $this->reads()->get($reply);

        self::assertSame([$root], array_map(static fn ($item): string => $item->id(), $detail->thread));
        self::assertSame('Re: Offer', $detail->message?->subject);
    }

    /**
     * @return array{int, int}
     */
    private function counts(ItemRow $row): array
    {
        return [$row->otherInThread, $row->outputCount];
    }

    private function reads(): ItemReads
    {
        return self::getContainer()->get(ItemReads::class);
    }
}
