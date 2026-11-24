<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Purchasing\Contract\Reading\ReadField;

/**
 * What applying a reading did: the fields taken into the draft, the fields left
 * for a person to take, and the codes of the warnings it gives.
 */
final readonly class AppliedReading
{
    /**
     * @param list<ReadField> $applied
     * @param list<ReadField> $suggested
     * @param list<string>    $warnings  codes of ReceivedDocumentWarning
     */
    public function __construct(
        public array $applied,
        public array $suggested,
        public array $warnings,
    ) {
    }
}
