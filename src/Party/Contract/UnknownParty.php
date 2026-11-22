<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * No contact with the id PartyDirectory was asked for: it never existed or it was removed.
 * An archived contact is not unknown.
 */
final class UnknownParty extends \RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(\sprintf('There is no contact with the id "%s".', $id));
    }
}
