<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

/**
 * An import action the current state or the user's input does not allow. The
 * reason is a translation key under "import.refused" and the parameters fill
 * its placeholders, so the screen, the API and MCP explain it the same way.
 */
final class ImportRefused extends \DomainException
{
    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $parameters = [],
    ) {
        parent::__construct($reason);
    }

    public static function notFound(): self
    {
        return new self('import.refused.not_found');
    }
}
