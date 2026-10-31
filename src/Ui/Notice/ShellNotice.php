<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Notice;

use App\Ui\Component\Style;

/**
 * A message the app shell shows above the page content, with a link to where
 * the user can read more, for example "the import finished".
 */
final readonly class ShellNotice
{
    private const array TONES = ['success', 'warning', 'error', 'info'];

    /**
     * @param string $tone a tone of the Alert component: success, warning, error or info
     */
    public function __construct(
        public string $tone,
        public string $text,
        public string $linkLabel,
        public string $href,
    ) {
        if (!\in_array($tone, self::TONES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown notice tone "%s".', $tone));
        }
        if ('' === $text || '' === $linkLabel) {
            throw new \InvalidArgumentException('A notice needs a text and a link label.');
        }
        Style::href($href);
    }
}
