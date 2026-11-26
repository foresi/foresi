<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class FixtureContactNoteHandler implements OperationHandler
{
    public function __construct(private FixtureNotes $notes)
    {
    }

    public static function input(): string
    {
        return FixtureContactNoteInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof FixtureContactNoteInput);

        return new OperationOutput(['id' => $this->notes->make($input->text)], 'Made the contact note.');
    }
}
