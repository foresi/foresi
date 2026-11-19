<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\View;

use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\MovementFilter;
use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Shared\Application\View\ListRecordCounter;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * How many records a view of the bank screen holds, compiled as the screen compiles
 * it: the movements, or the messages of a view of the bank mail. A bank movement is
 * a payment that came or went, so a token reads them with the payments scope; the
 * screen is for staff only, and so is its count.
 */
final readonly class MovementRecordCounter implements ListRecordCounter
{
    public const string SCOPE = 'payments:read';

    public function __construct(private BankMovements $movements, private InboundMessages $messages, private StaffCheck $staff)
    {
    }

    public function lists(): array
    {
        return [BankMovementLists::KEY];
    }

    public function scope(): string
    {
        return self::SCOPE;
    }

    public function count(string $listKey, ViewFilter $filter): int
    {
        if (BankMovementLists::KEY !== $listKey) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" is not the bank screen.', $listKey));
        }
        if (!$this->staff->isStaff()) {
            throw new \DomainException('Only staff work with bank movements.');
        }
        $view = new MovementFilter($filter);

        return $view->mail() ? $this->messages->count($view) : $this->movements->count($view);
    }
}
