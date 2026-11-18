<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * Which part of the bank screen to show: the movements a view holds, or the bank
 * mail that came when the view asks for it (BankMovementLists), newest first. The
 * text narrows the movements by partner, symbol, message or amount; one movement
 * can be asked for by its id, as a payment links to it. The views whose tabs show a
 * count are counted within the same text (spec D9).
 */
final readonly class MovementFilter
{
    public const int PER_PAGE = 25;

    /**
     * @param array<string, ViewFilter> $counted the filters of the views whose tabs show a count, by view key
     */
    public function __construct(
        public ViewFilter $view = new ViewFilter(),
        public string $text = '',
        public int $page = 1,
        public ?string $movementId = null,
        public array $counted = [],
    ) {
    }

    /**
     * Whether the screen lists the bank mail rather than the movements.
     */
    public function mail(): bool
    {
        return BankMovementLists::showsMail($this->view);
    }
}
