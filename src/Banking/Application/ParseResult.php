<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * What a parser read from a mail: one or more movements, or nothing to record,
 * with the reason, when the bank's mail is not a movement notice.
 */
final readonly class ParseResult
{
    /**
     * @param list<ParsedMovement> $movements
     */
    private function __construct(private array $movements, private ?string $ignoredReason)
    {
    }

    /**
     * @param list<ParsedMovement> $movements
     */
    public static function withMovements(array $movements): self
    {
        if ([] === $movements) {
            throw new \InvalidArgumentException('A mail with no movement is ignored, with its reason.');
        }

        return new self($movements, null);
    }

    public static function ignored(string $reason): self
    {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('An ignored mail needs a reason.');
        }

        return new self([], $reason);
    }

    /**
     * @return list<ParsedMovement>
     */
    public function movements(): array
    {
        return $this->movements;
    }

    public function ignoredReason(): ?string
    {
        return $this->ignoredReason;
    }
}
