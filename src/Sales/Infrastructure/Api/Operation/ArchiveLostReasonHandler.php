<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\ArchiveLostReasonInput;
use Foresi\Sales\Application\LostReasonWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

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
