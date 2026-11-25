<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Activities\Application\ActivityTargets;
use Foresi\Identity\Domain\Role;
use Foresi\Inbox\Application\Api\LinkItemInput;
use Foresi\Inbox\Contract\InboxAttachment;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxOutput;
use Foresi\Mail\Contract\SenderCheck;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\FakeActivityTarget;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\InboxTestCase;

/**
 * The item as the contract hands it to other modules: the mail's own words, its attachments, its
 * conversation and what came of it, and nothing for an item that is not there or not theirs.
 */
final class ItemsForOtherModulesTest extends InboxTestCase
{
    public function testTheOwnerSeesTheMailItsAttachmentsAndItsConversation(): void
    {
        $root = $this->receive(InboxMail::plain($this->address, messageId: 'root@client-a.example.com', subject: 'Offer'));
        $itemId = $this->receive($this->withAttachment());

        $view = $this->items()->find($itemId);

        self::assertInstanceOf(InboxItemView::class, $view);
        self::assertSame(self::CLIENT, $view->sender);
        self::assertSame('Offer with a file', $view->subject);
        self::assertSame(SenderCheck::VERIFIED, $view->verdict);
        self::assertSame("Hello, the offer is attached.", $view->text);
        self::assertEquals([new InboxAttachment(0, 'offer.pdf', 'application/pdf', 5)], $view->attachments);
        self::assertSame([$root], $view->threadItemIds);
    }

    public function testAnItemWhoseMailIsGoneHasNoSenderNoSubjectAndAnUnknownVerdict(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->em->getConnection()->executeStatement('DELETE FROM mail_message');

        $view = $this->items()->find($itemId);

        self::assertInstanceOf(InboxItemView::class, $view);
        self::assertNull($view->sender);
        self::assertSame('', $view->subject);
        self::assertSame(SenderCheck::UNKNOWN, $view->verdict);
        self::assertNull($view->text);
        self::assertSame([], $view->attachments);
    }

    public function testAnItemThatDoesNotExistIsNone(): void
    {
        self::assertNull($this->items()->find(Id::generate()));
        self::assertNull($this->items()->attachmentContent(Id::generate(), 0));
    }

    public function testAColleagueGetsTheOutputsHeSeesAsAListAndNoThread(): void
    {
        $deals = new FakeActivityTarget('deal');
        $unseen = Id::generate();
        $seen = Id::generate();
        $deals->add($unseen, 'Secret deal');
        $deals->add($seen, 'Example deal');
        self::getContainer()->set(ActivityTargets::class, new ActivityTargets([$deals]));
        $this->receive(InboxMail::plain($this->address, messageId: 'root@client-a.example.com', subject: 'Offer'));
        $itemId = $this->receive(InboxMail::plain($this->address, messageId: 'reply@client-a.example.com', subject: 'Re: Offer', inReplyTo: 'root@client-a.example.com', references: ['root@client-a.example.com']));
        $this->writes()->link(new LinkItemInput($itemId, 'deal', $unseen));
        $this->writes()->link(new LinkItemInput($itemId, 'deal', $seen));
        $deals->hide($unseen);
        $this->signIn($this->person('member@example.com', Role::Member));

        $view = $this->items()->find($itemId);

        self::assertInstanceOf(InboxItemView::class, $view);
        self::assertSame([], $view->threadItemIds);
        self::assertSame([$seen], array_map(static fn (InboxOutput $output): string => $output->recordId, $view->outputs));
        self::assertTrue(array_is_list($view->outputs));
    }

    private function items(): InboxItems
    {
        return self::getContainer()->get(InboxItems::class);
    }

    private function withAttachment(): string
    {
        return implode("\r\n", [
            'Authentication-Results: '.InboxMail::verifiedBy('client-a.example.com'),
            'From: Client A <'.self::CLIENT.'>',
            'To: '.$this->address,
            'Subject: Offer with a file',
            'Date: Tue, 24 Nov 2026 09:35:00 +0100',
            'Message-ID: <offer-2@client-a.example.com>',
            'In-Reply-To: <root@client-a.example.com>',
            'References: <root@client-a.example.com>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            'Hello, the offer is attached.',
            '--b1',
            'Content-Type: application/pdf; name="offer.pdf"',
            'Content-Disposition: attachment; filename="offer.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-'),
            '--b1--',
            '',
        ]);
    }
}
