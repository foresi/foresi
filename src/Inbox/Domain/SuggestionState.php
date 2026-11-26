<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

/**
 * Where a suggestion stands: proposed (open) until its owner accepts or rejects
 * it, or the agent that proposed it replaces it with another (superseded).
 * Accepted, rejected and superseded are final.
 */
enum SuggestionState: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
