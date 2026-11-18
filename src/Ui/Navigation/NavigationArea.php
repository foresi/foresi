<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Navigation;

use Foresi\Ui\Component\Style;

/**
 * A business area of the navigation: one rail entry with its own sidebar of
 * sections. Areas are UI metadata only. The label and the caption (the process
 * the area covers, in plain words) are translation keys, the icon is in the
 * locked icon set, and areas are shown by weight, then by registration order.
 *
 * "settings" is the one area that is not a business area: it sits apart at the
 * bottom of the rail, whatever its weight.
 */
final readonly class NavigationArea
{
    public const string TAG = 'app.navigation_area';

    public const string SETTINGS = 'settings';

    public function __construct(
        public string $id,
        public string $labelKey,
        public string $captionKey,
        public string $icon,
        public int $weight = 100,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            throw new \InvalidArgumentException(sprintf('Navigation area id "%s" must be lowercase letters, digits and underscores.', $id));
        }
        if ('' === $labelKey || '' === $captionKey) {
            throw new \InvalidArgumentException('A navigation area needs a label key and a caption key.');
        }
        Style::icon($icon);
    }
}
