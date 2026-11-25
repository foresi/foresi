<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

/**
 * Where an inbox item stands for its owner: new until they deal with it, then
 * done (handled) or discarded (not worth keeping). Either can be reopened.
 */
enum ItemState: string
{
    case New = 'new';
    case Done = 'done';
    case Discarded = 'discarded';
}
