<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\ContactLink;
use App\Party\Domain\Party;

/**
 * Links a contact person to a company or sole trader. A primary link demotes
 * the company's previous primary one.
 */
final readonly class LinkContactPerson
{
    public function __construct(private ContactLinks $links, private PartyDetails $details, private TransactionalWork $transaction)
    {
    }

    /**
     * @throws \DomainException when the kinds do not fit or the pair is linked already
     */
    public function link(Party $company, Party $person, string $position, bool $primary): ContactLink
    {
        return $this->transaction->run(function () use ($company, $person, $position, $primary): ContactLink {
            $link = new ContactLink($company, $person, $position, $primary);
            if ($primary) {
                foreach ($this->details->contactLinks($company) as $existing) {
                    if ($existing->company() === $company) {
                        $existing->markPrimary(false);
                    }
                }
            }
            $this->links->add($link);

            return $link;
        });
    }
}
