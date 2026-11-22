<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api\Operation;

use Foresi\Party\Application\AddVatStatusInput;
use Foresi\Party\Application\VatStatusWrites;
use Foresi\Party\Infrastructure\Api\FirmJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class AddVatStatusHandler implements OperationHandler
{
    public function __construct(private VatStatusWrites $writes)
    {
    }

    public static function input(): string
    {
        return AddVatStatusInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddVatStatusInput);
        $stretch = $this->writes->add($input);
        $length = $stretch->periodLength instanceof \Foresi\Party\Contract\VatPeriodLength ? ', with VAT periods of a '.$stretch->periodLength->value.',' : '';

        return new OperationOutput(FirmJson::vatStatus($stretch), \sprintf('The firm is %s%s from %s.', $stretch->status->value, $length, $stretch->validFrom?->format('Y-m-d') ?? 'the start'));
    }
}
