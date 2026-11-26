<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Shared\Domain\Id;

/**
 * The notes the tests' module made, kept for the length of one test.
 */
final class FixtureNotes
{
    public const string KIND = 'fixture_note';

    /** @var array<string, string> id => text */
    public array $made = [];

    public function make(string $text): string
    {
        $id = Id::generate();
        $this->made[$id] = $text;

        return $id;
    }
}
