<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

/**
 * The API token a suggestion is proposed with: its id, by which an agent
 * replaces only its own suggestions, and the name of its client as the person
 * sees it on the card (the token's name; an OAuth client's name for a
 * connection).
 */
final readonly class ProposingClient
{
    public function __construct(public string $tokenId, public string $clientName)
    {
    }
}
