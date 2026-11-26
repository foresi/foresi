<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Slot;

/**
 * The screen slots a module can fill with a SlotProvider. A slot with no
 * provider installed is empty, and the screen renders without it.
 */
final class Slots
{
    public const string TAG = 'foresi.ui.slot_provider';

    /** Widgets above the page header of every shell page, such as the running timer. Context: member (bool). */
    public const string SHELL_WIDGETS = 'ui.shell_widgets';

    /** Items of the account menu. Context: shell (ShellView). */
    public const string USER_MENU = 'ui.user_menu';

    /** The last section of the account menu, such as sign-out. Context: shell (ShellView). */
    public const string USER_MENU_END = 'ui.user_menu_end';

    private function __construct()
    {
    }
}
