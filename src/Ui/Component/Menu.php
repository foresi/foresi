<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A menu button and its popup menu (WAI-ARIA menu button, dropdown_controller.js). The content is
 * Ui:MenuItem rows, optionally in Ui:MenuSection groups; forms around items are fine.
 *
 * Looks as level.io's menus: 4 px padding, 1 px edge, a shadow, 40 px option rows with a 6 px
 * radius, a check on the selected row, uppercase group labels, dividers and a red destructive item.
 * The Combobox list wears the same panel and option look (PANEL, OPTION).
 *
 * The trigger is a Ui:Button (with "chevron") or, with "iconOnly", a Ui:IconButton; a page can
 * write its own in the "trigger" block, with the id triggerId() and the dropdown targets.
 */
#[AsTwigComponent]
final class Menu
{
    /** The popup panel of a menu or a listbox. */
    public const string PANEL = 'rounded-control border border-container bg-white p-1 shadow-floating';

    /** One option row of a menu or a listbox. */
    public const string OPTION = 'group flex min-h-control w-full cursor-pointer items-center gap-2 rounded-option border-0 bg-transparent px-3 py-2.5 text-left font-medium text-ink no-underline transition-colors';

    /** A compact option row (32 px, small text) for the row menu of a data table; still above the 24 px target of WCAG 2.5.8. */
    public const string OPTION_SM = 'group flex min-h-8 w-full cursor-pointer items-center gap-2 rounded-option border-0 bg-transparent px-2.5 py-1.5 text-left text-small font-medium text-ink no-underline transition-colors';

    private const array PLACEMENTS = ['below' => 'top-full mt-1', 'above' => 'bottom-full mb-1'];

    private const array ALIGNS = ['end' => 'right-0', 'start' => 'left-0'];

    /** The id of the popup menu. */
    public string $id = '';
    /** The id of the trigger; the menu id with "-button" when not given. */
    public ?string $buttonId = null;
    /** The trigger's text, or its accessible name with "iconOnly". */
    public string $label = '';
    public ?string $icon = null;
    public bool $iconOnly = false;
    public string $variant = 'secondary';
    public string $size = 'md';
    /** A chevron after the trigger text: "down" or "up" (a menu that opens above). */
    public ?string $chevron = null;
    public string $placement = 'below';
    public string $align = 'end';
    /** Placed with fixed positioning next to the trigger, so a scrolling container cannot clip it. */
    public bool $float = false;
    /** The menu's own accessible name, when the trigger's text does not name it. */
    public ?string $menuLabel = null;
    /** What the popup is: "menu" (menu items) or "dialog" (fields of its own, such as Ui:MultiSelect). */
    public string $popup = 'menu';
    /** Extra classes of the popup, for its width. */
    public string $menuClass = '';

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('A menu needs an id.');
        }
        if ('' === trim($this->label)) {
            throw new \InvalidArgumentException('A menu needs a label for its trigger.');
        }
        if ($this->iconOnly && null === $this->icon) {
            throw new \InvalidArgumentException('An icon-only menu trigger needs an icon.');
        }
        if (null !== $this->chevron && !in_array($this->chevron, ['down', 'up'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown chevron "%s", expected down or up.', $this->chevron));
        }
        if (!in_array($this->popup, ['menu', 'dialog'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown popup "%s", expected menu or dialog.', $this->popup));
        }
        Style::button($this->variant, $this->size);
        Style::icon($this->icon);
        $this->panelClasses();
    }

    public function triggerId(): string
    {
        return $this->buttonId ?? $this->id.'-button';
    }

    public function panelClasses(): string
    {
        $placement = self::PLACEMENTS[$this->placement] ?? throw new \InvalidArgumentException(sprintf('Unknown placement "%s", expected below or above.', $this->placement));
        $align = self::ALIGNS[$this->align] ?? throw new \InvalidArgumentException(sprintf('Unknown align "%s", expected end or start.', $this->align));

        return trim('absolute z-50 w-max min-w-40 text-left '.$placement.' '.$align.' '.self::PANEL.' '.$this->menuClass);
    }

    public function chevronClasses(): string
    {
        return Style::buttonIcon($this->size).' text-muted';
    }
}
