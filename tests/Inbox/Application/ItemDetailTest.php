<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\ItemDetail;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ThreadKey;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\SenderCheck;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

/**
 * What the person should know before trusting a mail, said as warnings.
 */
final class ItemDetailTest extends TestCase
{
    public function testAnUnverifiedForwardedDuplicateMailCarriesAllThreeWarnings(): void
    {
        $detail = $this->detail($this->item(forwarded: true, duplicateOfPurpose: 'bank_feed'), $this->message(SenderCheck::UNKNOWN));

        self::assertSame([ItemDetail::SENDER_NOT_VERIFIED, ItemDetail::FORWARDED_NOT_VERIFIED, ItemDetail::DUPLICATE], $detail->warnings());
    }

    public function testAVerifiedPlainMailCarriesNone(): void
    {
        self::assertSame([], $this->detail($this->item(), $this->message(SenderCheck::VERIFIED))->warnings());
    }

    public function testAMailMailRemovedHasNoSenderToDoubt(): void
    {
        self::assertSame([ItemDetail::FORWARDED_NOT_VERIFIED], $this->detail($this->item(forwarded: true), null)->warnings());
    }

    private function detail(InboxItem $item, ?MailMessageView $message): ItemDetail
    {
        return new ItemDetail($item, $message, null, [], [], []);
    }

    private function message(string $verdict): MailMessageView
    {
        return new MailMessageView(Id::generate(), Id::generate(), 'inbox', Id::generate(), 'webhook', null, null, [], 'client.a@client-a.example.com', 'Offer', null, new \DateTimeImmutable(), $verdict, str_repeat('a', 64), 100);
    }

    private function item(bool $forwarded = false, ?string $duplicateOfPurpose = null): InboxItem
    {
        $id = Id::generate();

        return new InboxItem($id, Id::generate(), Id::generate(), new \DateTimeImmutable(), $forwarded, $forwarded ? 'client.a@client-a.example.com' : null, ThreadKey::of([], null, 'm@example.com', $id), $duplicateOfPurpose);
    }
}
