<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * The thing a contract event is about, such as an invoice and its id. The type
 * is a lower case snake case word and the id is at most 64 characters, so a
 * subject always fits an index and a link.
 */
final readonly class EventSubject
{
    /**
     * @throws \InvalidArgumentException when the type or the id is malformed
     */
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $type)) {
            throw new \InvalidArgumentException(\sprintf('An event subject type is lower case snake case, "%s" is not.', $type));
        }

        $length = \strlen($id);
        if ($length < 1 || $length > 64) {
            throw new \InvalidArgumentException('An event subject id has 1 to 64 characters.');
        }
    }
}
