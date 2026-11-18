<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api\Operation;

use Foresi\Party\Application\Contact\ContactWrites;
use Foresi\Party\Application\Contact\LinkContactPersonInput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * contacts.link_person: the company with its contact persons.
 */
final readonly class LinkContactPersonHandler implements OperationHandler
{
    public function __construct(private ContactWrites $writes, private ContactAnswer $answer)
    {
    }

    public static function input(): string
    {
        return LinkContactPersonInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof LinkContactPersonInput);

        return $this->answer->of($this->writes->linkPerson($input), 'Linked a contact person to %s.');
    }
}
