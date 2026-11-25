<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Mail\Contract\MailMessageView;

/**
 * An item on the list of the person's inbox, with how many other items of its
 * conversation they have, so a list can group them under the latest.
 */
final readonly class ItemRow
{
    /**
     * @param MailMessageView|null $message       null once Mail removed the mail
     * @param int                  $otherInThread the owner's other items of the thread, outside the trash
     */
    public function __construct(
        public InboxItem $item,
        public ?MailMessageView $message,
        public int $otherInThread,
        public int $outputCount,
    ) {
    }
}
