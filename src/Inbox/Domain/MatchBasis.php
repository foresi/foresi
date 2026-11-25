<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

/**
 * Why an item points to its contact: the sender's address is the contact's, the
 * sender's verified domain is the one company's, or the owner chose it.
 */
enum MatchBasis: string
{
    case Address = 'address';
    case Domain = 'domain';
    case Manual = 'manual';
}
