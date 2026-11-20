<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

/**
 * One page of the bank screen: the movements of the view, or the messages of a view
 * of the bank mail, the counts of the tabs that show one, and the accounts the
 * columns name. A view of movements counts them; a view of the bank mail counts
 * the messages that wait for someone.
 */
final readonly class MovementPage
{
    /**
     * @param list<MovementRow>                                                 $rows
     * @param list<MessageRow>                                                  $messages
     * @param array<string, array{firmId: string, label: string, iban: string}> $accounts by account id
     * @param array<string, int>                                                $counts   by the key of each view counted
     */
    public function __construct(
        public array $rows,
        public array $messages,
        public int $total,
        public int $page,
        public int $pages,
        public array $accounts,
        public array $counts = [],
        public bool $mail = false,
    ) {
    }
}
