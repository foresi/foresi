<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

/**
 * Where the firm's exchange rates come from: the source in use, whether a
 * person chose it or it is the default, the default, the sources the firm
 * may choose and every installed one, and the country whose rules decide,
 * null without rules.
 */
final readonly class RateSourceView
{
    /**
     * @param list<string> $allowed
     * @param list<string> $installed
     */
    public function __construct(
        public ?string $source,
        public bool $chosen,
        public ?string $default,
        public array $allowed,
        public array $installed,
        public ?string $country,
    ) {
    }
}
