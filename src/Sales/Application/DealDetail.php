<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;

/**
 * One deal with what is kept beside it: its stage and lost reason, its lines
 * and their value, its links to other deals and its contact persons.
 */
final readonly class DealDetail
{
    /**
     * @param list<DealLine>           $lines
     * @param list<DealLinkView>       $links          the oldest first
     * @param list<string>             $contactPersonIds in their order
     * @param list<PartyPersonSummary> $contactPersons the ones of them still linked to the client, in their order
     */
    public function __construct(
        public Deal $deal,
        public ?Stage $stage,
        public ?LostReason $lostReason,
        public array $lines,
        public DealValue $value,
        public array $links,
        public array $contactPersonIds,
        public array $contactPersons,
    ) {
    }
}
