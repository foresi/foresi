<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\TaxesAndContributions\Application\ProfileWrites;
use Foresi\TaxesAndContributions\Application\SetYearInputsInput;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesJson;

final readonly class SetYearInputsHandler implements OperationHandler
{
    public function __construct(private ProfileWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetYearInputsInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetYearInputsInput);
        $year = $this->writes->setYear($input);

        return new OperationOutput(TaxesJson::year($year), \sprintf('Set the tax inputs of %d.', $year->year));
    }
}
