<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api;

use Foresi\Inbox\Application\ItemReads;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The answer of every inbox write: the item as GET answers it, a one-line
 * summary that quotes nothing of the mail, and the item's address.
 */
final readonly class ItemAnswer
{
    public function __construct(private ItemReads $reads, private InboxJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public function of(InboxItem $item, string $summary): OperationOutput
    {
        return new OperationOutput(
            $this->json->item($this->reads->detail($item, true)),
            $summary,
            $this->urls->generate(InboxRoutes::ITEM, ['itemId' => $item->id()]),
        );
    }
}
