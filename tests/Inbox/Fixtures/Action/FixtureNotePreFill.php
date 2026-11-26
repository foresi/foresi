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
 * The note starts with the subject of the mail, as text.
 */
final readonly class FixtureNotePreFill implements InboxPreFill
{
    public function operation(): string
    {
        return 'inbox.actions.fixture_note';
    }

    public function prefill(InboxItemView $item): PreFill
    {
        return new PreFill(
            ['text' => mb_substr($item->subject, 0, 200), 'noteKind' => 'mail'],
            ['noteKind' => ['mail' => 'Mail', 'call' => 'Call']],
        );
    }
}
