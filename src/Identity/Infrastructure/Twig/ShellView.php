<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Twig;

use App\Identity\Application\Organisation;

/**
 * What the app shell shows about the signed-in user and the organisation; $member
 * tells whether they have an active membership, without which the data of the
 * organisation stays out of the shell.
 */
final readonly class ShellView
{
    public function __construct(
        public string $userName,
        public string $userEmail,
        public string $initials,
        public string $locale,
        public string $otherLocale,
        public bool $member,
        public ?Organisation $organisation,
    ) {
    }
}
