<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Slot;

/**
 * One contribution to a screen slot: a template to include, or plain data for a slot that renders
 * the items itself (tabs), or both. Lower weights come first.
 */
final readonly class SlotItem
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public ?string $template = null, public array $data = [], public int $weight = 0)
    {
        if ('' === $template) {
            throw new \InvalidArgumentException('A slot template cannot be empty.');
        }
    }
}
