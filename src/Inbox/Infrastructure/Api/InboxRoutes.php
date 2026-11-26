<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api;

/**
 * The names of the REST reads of the inbox; the writes are operations.
 */
final class InboxRoutes
{
    public const string ITEMS = 'api_v1_inbox_items';
    public const string ITEM = 'api_v1_inbox_item';
}
