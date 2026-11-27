<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

/**
 * The row locks a use case took, in order, as "item <id>" and "suggestion <id>" (what the
 * stores of LockRecordingItems and LockRecordingSuggestions saw), and the ids the suggestion
 * counts were asked for.
 */
final class LockLog
{
    /** @var list<string> */
    public array $locks = [];

    /** @var list<list<string>> */
    public array $counted = [];
}
