<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Mail\Contract\MessageRetention;

/**
 * Removes for good, with their outputs, the inbox items that have been in the
 * trash for more than 30 days, as tasks and time are. The mail goes
 * with the item unless a record still uses its e-mail activity as a source:
 * then Mail keeps it. The audit log keeps what the item was.
 */
final readonly class PurgeTrashedItems
{
    public const int DAYS = 30;

    public function __construct(
        private ItemStore $store,
        private InboxTransaction $transaction,
        private MessageRetention $retention,
        private LinkableRecords $records,
        private SuggestionStore $suggestions,
    ) {
    }

    /**
     * @return int how many items were removed
     */
    public function run(\DateTimeImmutable $now): int
    {
        return $this->transaction->run(function () use ($now): int {
            $expired = $this->store->trashedBefore($now->modify(\sprintf('-%d days', self::DAYS)));
            foreach ($expired as $item) {
                $activityId = $item->emailActivityId();
                if (null !== $activityId && $this->records->isLinked($activityId)) {
                    $this->retention->keep($item->mailMessageId());
                } else {
                    $this->retention->release($item->mailMessageId(), $now);
                }
                foreach ($this->store->outputs($item) as $output) {
                    $this->store->remove($output);
                }
                foreach ($this->suggestions->forItem($item) as $suggestion) {
                    $this->suggestions->remove($suggestion);
                }
                $this->store->remove($item);
            }

            return \count($expired);
        });
    }
}
