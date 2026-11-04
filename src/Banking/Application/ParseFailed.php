<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * A bank's mail could not be read. The detail names the field or layout for the
 * operator; it never quotes the mail itself.
 */
final class ParseFailed extends \RuntimeException
{
    public function __construct(
        public readonly ParseFailure $reason,
        public readonly string $detail,
    ) {
        parent::__construct($detail);
    }
}
