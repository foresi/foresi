<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api\Operation;

use Foresi\Party\Application\Contact\ArchiveContactInput;
use Foresi\Party\Application\Contact\ContactWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * contacts.archive: the archived contact.
 */
final readonly class ArchiveContactHandler implements OperationHandler
{
    public function __construct(private ContactWrites $writes, private ContactAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ArchiveContactInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ArchiveContactInput);

        return $this->answer->of($this->writes->archive($input), 'Archived the contact %s.');
    }
}
