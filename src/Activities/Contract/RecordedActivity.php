<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Contract;

/**
 * An activity another module records as done (Inbox logs a mail it was sent or received).
 */
final readonly class RecordedActivity
{
    /**
     * @param string                                $typeKey     the key of an active activity type, such as email
     * @param string                                $body        Markdown, as the activity screens show it; at most 20000 characters
     * @param string                                $ownerUserId an owner or member of the organisation
     * @param list<array{kind: string, id: string}> $links       the records it is about, as ActivityTarget offers them
     */
    public function __construct(
        public string $typeKey,
        public string $subject,
        public string $body,
        public \DateTimeImmutable $doneAt,
        public string $ownerUserId,
        public array $links = [],
    ) {
    }
}
