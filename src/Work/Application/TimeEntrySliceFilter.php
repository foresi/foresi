<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * What a slice of the time entries is narrowed to, as GET /api/v1/time-entries
 * and its tool ask for it. A running timer is in it, without minutes.
 */
final readonly class TimeEntrySliceFilter
{
    /** The entries of the signed-in person. */
    public const string ME = 'me';

    /**
     * @param string|null $person ME, a membership id, or null for everyone's time the person sees
     */
    public function __construct(
        public ?string $projectId = null,
        public ?string $taskId = null,
        public ?string $person = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $until = null,
    ) {
    }
}
