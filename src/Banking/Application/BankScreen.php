<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Application\View\BankMovementLists;
use Psr\Clock\ClockInterface;

/**
 * What the bank screen shows: a page of the movements of a view, or of the bank
 * mail, with the counts its tabs carry: one query counts the views of movements
 * within the search (spec D9); a view of the bank mail counts the messages that
 * wait for someone, as the Messages tab did.
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
        $stuckBefore = self::stuckBefore($this->clock);

        $mail = $filter->mail();
        $total = $mail ? $this->messages->count($filter) : $this->movements->count($filter);
        $pages = max(1, (int) ceil($total / MovementFilter::PER_PAGE));
        $page = max(1, min($filter->page, $pages));
        $offset = ($page - 1) * MovementFilter::PER_PAGE;
        $ofMail = array_filter($filter->counted, BankMovementLists::showsMail(...));
        $ofMovements = array_diff_key($filter->counted, $ofMail);
        $counts = [] === $ofMovements ? [] : $this->movements->counts($filter->text, $ofMovements);
        foreach ($ofMail as $key => $view) {
            $counts[$key] = $this->messages->needingAttention($stuckBefore, $view);
        }

        return new MovementPage(
            $mail ? [] : $this->movements->rows($filter, $offset, MovementFilter::PER_PAGE),
            $mail ? $this->messages->rows($filter, $stuckBefore, $offset, MovementFilter::PER_PAGE) : [],
            $total,
            $page,
            $pages,
            array_map(static fn (array $account): array => ['firmId' => $account['firmId'], 'label' => $account['label'], 'iban' => $account['iban']], $accounts),
            $counts,
            $mail,
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
