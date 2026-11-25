<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Activities\Contract\LinkedRecord;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Shared\Domain\Id;

/**
 * The person's own inbox: a page of their items outside the trash, the newest
 * first, and one item with its mail. Nobody else's items are listed, and
 * another person's item is not_found; a record's viewers see the mail
 * through its e-mail activity instead.
 */
final readonly class ItemReads
{
    public const int MAX_LIMIT = 100;

    public function __construct(
        private InboxAccess $access,
        private ItemStore $store,
        private Messages $messages,
        private LinkableRecords $records,
    ) {
    }

    /**
     * @param ItemState|null $state     only the items in this state
     * @param bool           $unmatched only the items matched to no contact
     * @param string|null    $afterId   the id of the last item of the previous page
     *
     * @throws InboxRefused forbidden
     */
    public function list(?ItemState $state, bool $unmatched, ?string $afterId, int $limit): ItemSlice
    {
        $owner = $this->access->me();
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        // A cursor that is no item id, such as a crafted ?after=, starts at the top.
        $afterId = null !== $afterId && Id::isValid($afterId) ? $afterId : null;
        $items = $this->store->page($owner, $state, $unmatched, $afterId, $limit + 1);
        $hasMore = \count($items) > $limit;
        $items = \array_slice($items, 0, $limit);
        $sizes = $this->store->threadSizes($owner, array_map(static fn (InboxItem $item): string => $item->threadKey(), $items));
        $outputs = $this->store->outputCounts(array_map(static fn (InboxItem $item): string => $item->id(), $items));
        $messages = $this->messages->findMany(array_map(static fn (InboxItem $item): string => $item->mailMessageId(), $items));

        return new ItemSlice(array_map(fn (InboxItem $item): ItemRow => new ItemRow(
            $item,
            $messages[$item->mailMessageId()] ?? null,
            max(0, ($sizes[$item->threadKey()] ?? 1) - 1),
            $outputs[$item->id()] ?? 0,
        ), $items), $hasMore);
    }

    /**
     * How many new items the person has, counted up to the limit (the badge of the navigation).
     *
     * @throws InboxRefused forbidden
     */
    public function newCount(int $limit): int
    {
        return $this->store->countInState($this->access->me(), ItemState::New, $limit);
    }

    /**
     * @throws InboxRefused not_found, forbidden
     */
    public function get(string $itemId): ItemDetail
    {
        return $this->detail($this->access->own($itemId), true);
    }

    /**
     * The item as anyone who may see it reads it; the thread only for its owner.
     */
    public function detail(InboxItem $item, bool $withThread): ItemDetail
    {
        $message = $this->messages->find($item->mailMessageId());

        return new ItemDetail(
            $item,
            $message,
            $message instanceof MailMessageView ? $this->messages->text($message->id) : null,
            $message instanceof MailMessageView ? $this->messages->attachments($message->id) : [],
            $withThread ? $this->store->threadOf($item) : [],
            array_map(function (ItemOutput $output): OutputView {
                $record = $this->records->describe($output->kind(), $output->recordId());

                return $record instanceof LinkedRecord
                    ? new OutputView($output, true, $record->label, $record->url)
                    : new OutputView($output, false, null, null);
            }, $this->store->outputs($item)),
        );
    }
}
