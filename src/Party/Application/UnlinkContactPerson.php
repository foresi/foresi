<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Party;

/**
 * Takes a contact person off a company or sole trader. Both contacts stay; only
 * the link goes, with its position. If it was the primary link, the company
 * has none until another is marked.
 */
final readonly class UnlinkContactPerson
{
    public function __construct(
        private ContactLinks $links,
        private PartyDetails $details,
        private TransactionalWork $transaction,
        private PartyAccess $access,
    ) {
    }

    /**
     * @throws PartyNotAllowed for a guest
     * @throws PartyRefused    when the person is not linked to the company
     */
    public function unlink(Party $company, Party $person): void
    {
        $this->access->denyUnlessMayChange();
        foreach ($this->details->contactLinks($company) as $link) {
            if ($link->company() === $company && $link->person() === $person) {
                $this->transaction->run(fn () => $this->links->remove($link));

                return;
            }
        }

        throw new PartyRefused(PartyRefused::NOT_LINKED, 'personId');
    }
}
