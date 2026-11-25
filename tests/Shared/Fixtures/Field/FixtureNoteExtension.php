<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures\Field;

use Foresi\Shared\Contract\Field\RecordExtension;

/**
 * The extension interface of the test-only record, as a record's module publishes one.
 */
interface FixtureNoteExtension extends RecordExtension
{
    public const string RECORD = 'fixture_note';

    public function noteId(): string;
}
