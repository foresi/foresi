<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Api\Operation;

use App\Party\Application\Contact\ContactWrites;
use App\Party\Application\Contact\RestoreContactInput;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

/**
 * contacts.restore: the restored contact.
 */
final readonly class RestoreContactHandler implements OperationHandler
{
    public function __construct(private ContactWrites $writes, private ContactAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RestoreContactInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RestoreContactInput);

        return $this->answer->of($this->writes->restore($input), 'Restored the contact %s.');
    }
}
