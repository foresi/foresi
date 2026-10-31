<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The bar that carries the actions of a form or a wizard step. It is docked to
 * the bottom of the viewport while the page is longer than the screen and sits
 * under the content when the page is short. The buttons go in the content
 * block in reading order (Back or Cancel first, the primary action last), so
 * the primary ends up on the right. An optional short status text, given as
 * "status" or in the block "status", is shown on the left.
 */
#[AsTwigComponent]
final class ActionBar
{
    public ?string $status = null;

    public function statusText(): ?string
    {
        $status = null === $this->status ? '' : trim($this->status);

        return '' === $status ? null : $status;
    }
}
