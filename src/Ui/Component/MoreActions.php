<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The actions of a page or sheet header: the primary button, the one or two secondary buttons and
 * the "⋯" more-actions menu.
 *
 * It renders them in one fixed order, so no screen sets it: the primary button (Edit on a detail,
 * New on a list) first, then the secondary buttons, then "⋯" last, the right edge of the row.
 * Material's app bar keeps the overflow menu at the far end and moves actions into it from that end,
 * so the most used one stays visible longest; Confluence keeps "More actions" (ellipsis) at the top
 * right of a page. Polaris keeps one primary action and few secondary ones visible and moves the
 * rest into "More actions"; Primer moves the secondary actions into the overflow menu on a narrow
 * viewport and puts danger items at the end of a menu. Polaris Page puts the primary action at the
 * right edge; Foresi deliberately leads with it and ends with "⋯" (docs/design/overlays.md).
 * A header's actions are this component alone: nothing follows it (tests/Ui/HeaderActionOrderTest).
 *
 * Blocks, all optional:
 * - "primary": the screen's main action, first on every screen (a phone too);
 * - "buttons": the secondary buttons, shown from the sm breakpoint up;
 * - "phone": the same actions as Ui:MenuItem rows, shown in the menu only below sm;
 * - content: the other actions as Ui:MenuItem rows, most used first (workflow, share, export,
 *   import, settings);
 * - "last": archive, restore, trash or delete, in a group of its own at the end of the menu.
 *
 * The primary button, the buttons and the menu share one box-less wrapper, so on a phone the header
 * grid lays them out as its own items and keeps the menu beside the primary button. Without any menu row there is no
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
