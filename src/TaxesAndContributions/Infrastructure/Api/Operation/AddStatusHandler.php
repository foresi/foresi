<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\ProfileWrites;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesJson;

final readonly class AddStatusHandler implements OperationHandler
{
    public function __construct(private ProfileWrites $writes)
    {
    }

    public static function input(): string
    {
        return AddStatusInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddStatusInput);
        $status = $this->writes->addStatus($input);

        return new OperationOutput(TaxesJson::status($status), \sprintf('The owner is %s %s from %s.', $status->kind->value, $status->value, $status->validFrom->format('Y-m-d')));
    }
}
