<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * What the intake did with a statement.
 */
final readonly class IntakeResult
{
    /**
     * @param string|null  $statementId  the stored statement, or null for a bank mail, which is stored as a message
     * @param list<string> $recorded     the movements recorded from it
     * @param list<string> $corroborated the movements another channel had recorded, which it confirmed
     * @param int          $alreadyKnown the entries the same source had told before, skipped
     * @param int          $gapsDetected the gaps in the account's statements it opened
     * @param int          $gapsClosed   the gaps it closed
     */
    public function __construct(
        public ?string $statementId,
        public array $recorded,
        public array $corroborated,
        public int $alreadyKnown,
        public int $gapsDetected = 0,
        public int $gapsClosed = 0,
    ) {
    }
}
