<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\CreateLostReasonInput;
use Foresi\Sales\Application\LostReasonWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class CreateLostReasonHandler implements OperationHandler
{
    public function __construct(private LostReasonWrites $writes, private LostReasonAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateLostReasonInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateLostReasonInput);

        return $this->answer->of($this->writes->create($input), 'Added the lost reason %s.');
    }
}
