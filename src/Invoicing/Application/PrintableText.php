<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What a printed document can show. A character outside it would vanish from the
 * PDF, so a document holding one is refused when it is saved.
 */
interface PrintableText
{
    /**
     * @return list<string> the characters that cannot print, each once, in the order of the text
     */
    public function unprintable(string $text): array;
}
