<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Api\Operation;

use App\Party\Application\Contact\ContactWrites;
use App\Party\Application\Contact\UnlinkContactPersonInput;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

/**
 * contacts.unlink_person: the company with its contact persons.
 */
final readonly class UnlinkContactPersonHandler implements OperationHandler
{
    public function __construct(private ContactWrites $writes, private ContactAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UnlinkContactPersonInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UnlinkContactPersonInput);

        return $this->answer->of($this->writes->unlinkPerson($input), 'Unlinked a contact person from %s.');
    }
}
