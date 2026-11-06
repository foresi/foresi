<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

/**
 * Reads one iDoklad account. Only the read-only paths are ever requested; any
 * other path is refused before it is sent.
 */
interface IdokladSession
{
    /**
     * The Data of one answer.
     *
     * @param array<string, int|string> $query
     *
     * @throws IdokladFailure
     * @throws \App\Idoklad\Domain\IdokladPathRefused
     */
    public function get(string $path, array $query = []): mixed;

    /**
     * Every page of a list, 200 records at a time, until its last page.
     *
     * @param array<string, int|string> $query
     *
     * @return iterable<int, list<array<mixed>>>
     *
     * @throws IdokladFailure
     */
    public function pages(string $path, array $query = []): iterable;

    /**
     * The API requests this session has sent so far, refused ones included,
     * against the account's monthly quota. Token requests are not counted.
     */
    public function requests(): int;
}
