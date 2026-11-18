<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * The display each person last used on a view, kept on the server for them alone
 * (spec D-display-3), so it follows them to another device and back to the view.
 */
interface SavedViewUserStates
{
    /**
     * @return array<array-key, mixed>|null the display payload, or null when they use the view's own
     */
    public function display(string $viewId, string $userId): ?array;

    /**
     * @param array<array-key, mixed> $display
     */
    public function remember(string $viewId, string $userId, array $display): void;

    /**
     * Back to the view's own display, as Save and Reset leave it.
     */
    public function forget(string $viewId, string $userId): void;
}
