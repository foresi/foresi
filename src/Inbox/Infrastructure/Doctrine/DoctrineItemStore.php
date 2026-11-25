<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineItemStore implements ItemStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function item(string $id): ?InboxItem
    {
        return Id::isValid($id) ? $this->em->find(InboxItem::class, $id) : null;
    }

    public function itemOfMessage(string $mailMessageId): ?InboxItem
    {
        if (!Id::isValid($mailMessageId)) {
            return null;
        }

        return $this->em->getRepository(InboxItem::class)->findOneBy(['mailMessageId' => $mailMessageId]);
    }

    public function add(InboxItem|ItemOutput $record): void
    {
        $this->em->persist($record);
    }
}
