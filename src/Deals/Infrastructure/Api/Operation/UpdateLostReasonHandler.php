<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\UpdateLostReasonInput;
use Foresi\Deals\Application\LostReasonWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UpdateLostReasonHandler implements OperationHandler
{
    public function __construct(private LostReasonWrites $writes, private LostReasonAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateLostReasonInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateLostReasonInput);

        return $this->answer->of($this->writes->update($input), 'Renamed the lost reason %s.');
    }
}
