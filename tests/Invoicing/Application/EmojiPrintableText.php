<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\PrintableText;

/**
 * A font that prints everything but the two smileys 🙂 and 🙃, so the rule checks
 * need no font file.
 */
final readonly class EmojiPrintableText implements PrintableText
{
    public function unprintable(string $text): array
    {
        preg_match_all('/[\x{1F642}\x{1F643}]/u', $text, $matches);

        return array_values(array_unique($matches[0]));
    }
}
