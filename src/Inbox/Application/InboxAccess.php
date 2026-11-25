<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Inbox\Domain\InboxItem;

/**
 * The one place an item is read for the person acting: an owner or
 * member of the organisation, and only their own items. Another person's item,
 * an unknown one and one in the trash are alike not_found; the trash and its
 * restore alone reach an item there.
 */
final readonly class InboxAccess
{
    public function __construct(private AuditActor $actor, private StaffCheck $staff, private ItemStore $store)
    {
    }

    /**
     * @return string the id of the person acting
     *
     * @throws InboxRefused forbidden for nobody signed in or a guest
     */
    public function me(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw InboxRefused::forbidden();
        }

        return $userId;
    }

    /**
     * The person's own item, not in the trash.
     *
     * @throws InboxRefused not_found, forbidden
     */
    public function own(string $itemId): InboxItem
    {
        $item = $this->ownOrTrashed($itemId);
        if ($item->isTrashed()) {
            throw InboxRefused::notFound();
        }

        return $item;
    }

    /**
     * The person's own item, in the trash or not.
     *
     * @throws InboxRefused not_found, forbidden
     */
    public function ownOrTrashed(string $itemId): InboxItem
    {
        $userId = $this->me();
        $item = $this->store->item($itemId);
        if (!$item instanceof InboxItem || !$item->isOwnedBy($userId)) {
            throw InboxRefused::notFound();
        }

        return $item;
    }
}
