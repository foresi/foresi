<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Identity\Domain\User;
use Foresi\Inbox\Application\ForwardParser;
use Foresi\Inbox\Application\InboxTransaction;
use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Application\ReceiveItem;
use Foresi\Inbox\Application\SenderMatching;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Mail\Contract\CrossPurposeDuplicates;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\NotAccepted;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Inbox\Support\FakeContactsByEmail;
use Foresi\Tests\Inbox\Support\InboxMail;
use PHPUnit\Framework\TestCase;

/**
 * When a mail counts as the owner's own forward, and the mail the inbox refuses to take,
 * with the stored message and the owner as stubs.
 */
final class ReceiveItemOwnerTest extends TestCase
{
    /** @var list<InboxItem> */
    private array $added = [];

    public function testAMessageMailDoesNotKnowIsNotAccepted(): void
    {
        $this->expectException(NotAccepted::class);

        $this->receiver(null, 'owner@example.com')->receive(Id::generate());
    }

    public function testTheOwnersAddressCountsWhateverTheWhiteSpaceAndCaseAroundIt(): void
    {
        $this->receiver(' Owner@Example.com'."\t", 'owner@example.com', InboxMail::gmailForward('Client A <client.a@client-a.example.com>'))->receive(Id::generate());

        self::assertCount(1, $this->added);
        self::assertTrue($this->added[0]->isForwarded());
        self::assertSame('client.a@client-a.example.com', $this->added[0]->originalFrom());
    }

    public function testAnotherSendersMailIsNoForward(): void
    {
        $this->receiver('someone@example.com', 'owner@example.com', InboxMail::gmailForward('Client A <client.a@client-a.example.com>'))->receive(Id::generate());

        self::assertFalse($this->added[0]->isForwarded());
    }

    private function receiver(?string $from, string $ownerEmail, string $text = 'Hello'): ReceiveItem
    {
        $owner = new User($ownerEmail, 'hash', 'Example Owner');
        $message = null === $from ? null : new MailMessageView(Id::generate(), Id::generate(), 'inbox', $owner->id(), 'webhook', 'm@example.com', null, [], $from, 'Offer', null, new \DateTimeImmutable(), 'unknown', str_repeat('a', 64), 100);
        $messages = $this->createStub(Messages::class);
        $messages->method('find')->willReturn($message);
        $messages->method('text')->willReturn($text);
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn($owner);
        $staff = $this->createStub(StaffCheck::class);
        $staff->method('isStaffMember')->willReturn(true);
        $duplicates = $this->createStub(CrossPurposeDuplicates::class);
        $duplicates->method('of')->willReturn([]);
        $store = $this->createStub(ItemStore::class);
        $store->method('add')->willReturnCallback(function (InboxItem|\Foresi\Inbox\Domain\ItemOutput $record): void {
            if ($record instanceof InboxItem) {
                $this->added[] = $record;
            }
        });
        $transaction = $this->createStub(InboxTransaction::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $work): mixed => $work());

        return new ReceiveItem($messages, $duplicates, $users, $staff, new ForwardParser(), new SenderMatching(new FakeContactsByEmail()), $store, $transaction, $this->createStub(EventRecorder::class));
    }
}
