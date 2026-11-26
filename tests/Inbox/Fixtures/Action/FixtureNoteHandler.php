<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class FixtureNoteHandler implements OperationHandler
{
    public function __construct(private FixtureNotes $notes)
    {
    }

    public static function input(): string
    {
        return FixtureNoteInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof FixtureNoteInput);
        if ($input->fail) {
            throw new FixtureNoteRefused();
        }
        $id = $this->notes->make($input->text);

        return new OperationOutput(['id' => $id, 'text' => $input->text], 'Made the note.');
    }
}
