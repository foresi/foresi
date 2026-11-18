<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;

/**
 * Links a contact person to a company or sole trader. A primary link demotes
 * the company's previous primary one.
 */
final readonly class LinkContactPerson
{
    public function __construct(
        private ContactLinks $links,
        private PartyDetails $details,
        private TransactionalWork $transaction,
        private PartyAccess $access,
        private PartyRules $rules,
    ) {
    }

    /**
     * @throws PartyNotAllowed for a guest
     * @throws PartyRefused    when the kinds do not fit, the position is too long or the pair is linked already
     */
    public function link(Party $company, Party $person, string $position, bool $primary): ContactLink
    {
        $this->access->denyUnlessMayChange();
        $this->rules->checkLink($company, $person, $position);
        foreach ($this->details->contactLinks($company) as $existing) {
            if ($existing->person() === $person) {
                throw new PartyRefused(PartyRefused::LINKED, 'personId');
            }
        }

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
