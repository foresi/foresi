<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Dashboard;

/**
 * A part of the start page a module contributes: the template it renders with
 * its variables. Sections come in the order of their weight, the lightest first.
 */
final readonly class DashboardSection
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(public string $template, public array $context = [], public int $weight = 0)
    {
        if ('' === $template) {
            throw new \InvalidArgumentException('A dashboard section needs a template.');
        }
    }
}
