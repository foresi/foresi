<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api\Operation;

use Foresi\Party\Application\Contact\ContactWrites;
use Foresi\Party\Application\Contact\UpdateContactInput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * contacts.update: the contact as it is now.
 */
final readonly class UpdateContactHandler implements OperationHandler
{
    public function __construct(private ContactWrites $writes, private ContactAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateContactInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateContactInput);

        return $this->answer->of($this->writes->update($input), 'Updated the contact %s.');
    }
}
