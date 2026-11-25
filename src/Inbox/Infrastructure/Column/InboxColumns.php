<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Column;

use Foresi\Inbox\Application\View\InboxLists;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;

/**
 * The columns of the inbox; each cell renders one InboxCells. The subject names
 * the row and stays; the others give way on a phone. Every cell is the sender's
 * or a contact's text, printed escaped.
 */
final readonly class InboxColumns implements DataSource
{
    public const string LIST = InboxLists::KEY;

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }

        return [
            new Attribute('sender', 'inbox.column.sender', AttributeType::Text, static fn (InboxCells $row): string => $row->sender, defaultVisible: true, defaultPosition: 10, cell: static fn (InboxCells $row): string => $row->sender, phone: false, truncate: true),
            new Attribute('contact', 'inbox.column.contact', AttributeType::Text, static fn (InboxCells $row): string => $row->contact, defaultVisible: true, defaultPosition: 20, cell: static fn (InboxCells $row): string => $row->contact, phone: false, truncate: true),
            new Attribute('subject', 'inbox.column.subject', AttributeType::Text, static fn (InboxCells $row): string => $row->subject, defaultVisible: true, defaultPosition: 30, cell: static fn (InboxCells $row): string => $row->subject, locked: true, truncate: true),
            new Attribute('received', 'inbox.column.received', AttributeType::DateTime, static fn (InboxCells $row): string => $row->received, defaultVisible: true, defaultPosition: 40, cell: static fn (InboxCells $row): string => $row->received, phone: false),
            new Attribute('outputs', 'inbox.column.outputs', AttributeType::Text, static fn (InboxCells $row): string => $row->outputs, defaultVisible: true, defaultPosition: 50, cell: static fn (InboxCells $row): string => $row->outputs, phone: false),
        ];
    }
}
