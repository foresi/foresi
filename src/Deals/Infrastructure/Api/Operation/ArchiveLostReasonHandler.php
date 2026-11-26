<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\ArchiveLostReasonInput;
use Foresi\Deals\Application\LostReasonWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class ArchiveLostReasonHandler implements OperationHandler
{
    public function __construct(private LostReasonWrites $writes, private LostReasonAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ArchiveLostReasonInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ArchiveLostReasonInput);

        return $this->answer->of($this->writes->archive($input), 'Archived the lost reason %s.');
    }
}
