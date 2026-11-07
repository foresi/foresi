<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * One page of the bank screen: the movements of the tab, or the messages on the
 * Messages tab, and what the filters and tabs offer: how many incoming and how many
 * outgoing movements wait for a pairing and how many messages wait for someone.
 */
final readonly class MovementPage
{
    /**
     * @param list<MovementRow>                                                 $rows
     * @param list<MessageRow>                                                  $messages
     * @param array<string, string>                                             $firms    name by firm id, of the firms with an account
     * @param array<string, array{firmId: string, label: string, iban: string}> $accounts by account id, in the order of the firms
     */
    public function __construct(
        public array $rows,
        public array $messages,
        public int $total,
        public int $page,
        public int $pages,
        public int $unmatched,
        public int $attention,
        public array $firms,
        public array $accounts,
        public int $outgoingUnmatched = 0,
    ) {
    }
}
