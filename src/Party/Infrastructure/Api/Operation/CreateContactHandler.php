<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Api\Operation;

use App\Party\Application\Contact\ContactWrites;
use App\Party\Application\Contact\CreateContactInput;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

/**
 * contacts.create: the new contact, with its Location.
 */
final readonly class CreateContactHandler implements OperationHandler
{
    public function __construct(private ContactWrites $writes, private ContactAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateContactInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateContactInput);

        return $this->answer->of($this->writes->create($input), 'Created the contact %s.', created: true);
    }
}
