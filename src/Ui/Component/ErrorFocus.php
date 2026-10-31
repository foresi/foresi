<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Hands the autofocus of a page to its first invalid control. A form shown
 * again with errors puts the keyboard focus straight into the first field to
 * fix, even without JavaScript (the form-errors controller does the same and
 * scrolls the field to the middle of the viewport). Controls are mounted in
 * document order, so the first one to ask is the first one on the page. The
 * claim is forgotten after every request.
 */
final class ErrorFocus implements ResetInterface
{
    private bool $claimed = false;

    /**
     * True for the first invalid control of the request only.
     */
    public function claim(bool $invalid): bool
    {
        if (!$invalid || $this->claimed) {
            return false;
        }
        $this->claimed = true;

        return true;
    }

    public function reset(): void
    {
        $this->claimed = false;
    }
}
