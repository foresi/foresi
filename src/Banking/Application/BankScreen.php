<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use Psr\Clock\ClockInterface;

/**
 * What the bank screen shows of the active tenant: a page of the movements of a
 * tab, or of the bank mail, with the counts its tabs carry.
 *
 * A message still waiting for processing after an hour is stuck: the queue gave
 * up on it, or no worker runs. It counts among the messages that wait for
 * someone, so it is seen and can be tried again by hand.
 */
final readonly class BankScreen
{
    public const int STUCK_AFTER_MINUTES = 60;

    public function __construct(private BankMovements $movements, private InboundMessages $messages, private ClockInterface $clock)
    {
    }

    public function page(MovementFilter $filter): MovementPage
    {
        $accounts = $this->movements->accounts();
        $firms = [];
        foreach ($accounts as $account) {
            $firms[$account['firmId']] = $account['firmName'];
        }
        // A firm or an account of no one is no filter at all.
        $firmId = null !== $filter->firmId && isset($firms[$filter->firmId]) ? $filter->firmId : null;
        $accountId = null !== $filter->accountId && isset($accounts[$filter->accountId]) && (null === $firmId || $accounts[$filter->accountId]['firmId'] === $firmId) ? $filter->accountId : null;
        $filter = new MovementFilter($filter->tab, $firmId, $accountId, $filter->text, $filter->page, $filter->movementId);
        $stuckBefore = self::stuckBefore($this->clock);

        $messages = MovementFilter::MESSAGES === $filter->tab;
        $total = $messages ? $this->messages->count($filter) : $this->movements->count($filter);
        $pages = max(1, (int) ceil($total / MovementFilter::PER_PAGE));
        $page = max(1, min($filter->page, $pages));
        $offset = ($page - 1) * MovementFilter::PER_PAGE;

        return new MovementPage(
            $messages ? [] : $this->movements->rows($filter, $offset, MovementFilter::PER_PAGE),
            $messages ? $this->messages->rows($filter, $stuckBefore, $offset, MovementFilter::PER_PAGE) : [],
            $total,
            $page,
            $pages,
            $this->movements->count(new MovementFilter(MovementFilter::UNMATCHED, $firmId, $accountId)),
            $this->messages->needingAttention($stuckBefore, $firmId, $accountId),
            $firms,
            array_map(static fn (array $account): array => ['firmId' => $account['firmId'], 'label' => $account['label'], 'iban' => $account['iban']], $accounts),
            $this->movements->count(new MovementFilter(MovementFilter::OUTGOING_UNMATCHED, $firmId, $accountId)),
        );
    }

    /**
     * The moment before which a message still waiting is stuck.
     */
    public static function stuckBefore(ClockInterface $clock): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($clock->now())->modify(\sprintf('-%d minutes', self::STUCK_AFTER_MINUTES));
    }
}
