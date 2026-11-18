<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The "⋯" more-actions menu of a page header, and the one or two secondary buttons in front of it.
 *
 * Every page header holds, in this order: at most two secondary buttons, this menu, and the
 * primary button (Edit on a detail, New on a list) last. Polaris keeps one primary action and few
 * secondary ones visible and moves the rest into "More actions"; Primer moves the secondary actions
 * into the overflow menu on a narrow viewport and puts danger items at the end of a menu.
 *
 * Blocks, all optional:
 * - "buttons": the secondary buttons, shown from the sm breakpoint up;
 * - "phone": the same actions as Ui:MenuItem rows, shown in the menu only below sm;
 * - content: the other actions as Ui:MenuItem rows, most used first (workflow, share, export,
 *   import, settings);
 * - "last": archive, restore, trash or delete, in a group of its own at the end of the menu.
 *
 * The buttons and the menu share one box-less wrapper, so on a phone the header grid sees one
 * child for both and keeps the menu beside the primary button. Without any menu row there is no
 * trigger; with phone rows only, the trigger shows only below sm.
 */
#[AsTwigComponent]
final class MoreActions
{
    public string $menuId = 'page-actions';
    /** The trigger's accessible name; "More actions" when not given. */
    public ?string $menuLabel = null;
    /** The trigger's size, as the page's own buttons: "sm" in a compact sheet header. */
    public string $menuSize = 'md';

    #[PostMount]
    public function validate(): void
    {
        if ('' === trim($this->menuId)) {
            throw new \InvalidArgumentException('A more-actions menu needs an id.');
        }
        Style::button('secondary', $this->menuSize);
    }
}
