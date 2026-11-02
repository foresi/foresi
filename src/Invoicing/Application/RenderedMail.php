<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The subject and body of a mail: with tokens as a template, or filled in.
 */
final readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {
    }
}
