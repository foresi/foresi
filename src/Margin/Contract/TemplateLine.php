<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Contract;

/**
 * A line of a recurring invoice template, as Margin reads it: its own id, the
 * template it belongs to, the contact the template bills, what it says and where a person opens the template.
 */
final readonly class TemplateLine
{
    public function __construct(
        public string $id,
        public string $templateId,
        public string $customerId,
        public string $text,
        public string $url,
    ) {
    }
}
