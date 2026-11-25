<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Party\Contract\ContactsByEmail;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\PartySummary;

/**
 * Finds the contact of a sender in the address book, without guessing:
 *
 * 1. exactly one contact has the sender's address: that contact, by address; for
 *    a person linked to exactly one company or sole trader, that one too;
 * 2. otherwise, when the sender is verified and the domain is not a free mail
 *    provider's, exactly one company or sole trader with an address of that
 *    domain: that one, by domain;
 * 3. otherwise none.
 *
 * Several contacts at a step leave the sender unmatched and end the search
 * there. Archived contacts never match. It reads with nobody signed in, as mail
 * is received from the webhook or the mailbox poller.
 */
final readonly class SenderMatching
{
    public function __construct(private ContactsByEmail $contacts)
    {
    }

    /**
     * @param string|null $address  the sender's e-mail address, or the original sender a forward names
     * @param bool        $verified whether the receiving server vouched for the sender; never for a forward
     */
    public function match(?string $address, bool $verified): SenderMatch
    {
        $address = mb_strtolower(trim((string) $address));
        $at = strrpos($address, '@');
        if (false === $at || 0 === $at || $at === \strlen($address) - 1) {
            return SenderMatch::none();
        }

        $byAddress = $this->contacts->byAddress($address);
        if (1 === \count($byAddress)) {
            return SenderMatch::byAddress($byAddress[0]->id, $this->companyOf($byAddress[0]));
        }
        if ([] !== $byAddress) {
            return SenderMatch::none();
        }

        $domain = substr($address, $at + 1);
        if (!$verified || FreeMailDomains::isFree($domain)) {
            return SenderMatch::none();
        }
        $byDomain = $this->contacts->byDomain($domain);

        return 1 === \count($byDomain) ? SenderMatch::byDomain($byDomain[0]->id) : SenderMatch::none();
    }

    /**
     * The one company or sole trader a person is linked to, archived ones left
     * out; none for a company or sole trader, and for a person with several.
     */
    public function companyOf(PartySummary $contact): ?string
    {
        if (ContactKind::Person !== $contact->kind) {
            return null;
        }
        $companies = array_values(array_filter(
            $this->contacts->companiesOfPerson($contact->id),
            static fn (PartySummary $company): bool => !$company->archived,
        ));

        return 1 === \count($companies) ? $companies[0]->id : null;
    }
}
