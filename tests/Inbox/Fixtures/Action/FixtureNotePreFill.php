<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxPreFill;
use Foresi\Inbox\Contract\PreFill;

/**
 * The note starts with the subject of the mail, as text. A mail with the
 * subject BREAKS makes it fail, as a pre-fill with a bug would.
 */
final readonly class FixtureNotePreFill implements InboxPreFill
{
    public const string BREAKS = 'Break the pre-fill';

    public function operation(): string
    {
        return 'inbox.actions.fixture_note';
    }

    public function prefill(InboxItemView $item): PreFill
    {
        if (self::BREAKS === $item->subject) {
            throw new \RuntimeException('The pre-fill broke.');
        }

        return new PreFill(
            ['text' => mb_substr($item->subject, 0, 200), 'noteKind' => 'mail'],
            ['noteKind' => ['mail' => 'Mail', 'call' => 'Call']],
        );
    }
}
