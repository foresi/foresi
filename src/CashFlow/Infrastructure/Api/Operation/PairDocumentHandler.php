<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api\Operation;

use Foresi\CashFlow\Application\PairDocumentInput;
use Foresi\CashFlow\Application\PairingWrites;
use Foresi\CashFlow\Infrastructure\Api\PairingAnswers;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class PairDocumentHandler implements OperationHandler
{
    public function __construct(private PairingWrites $writes, private PairingAnswers $answers)
    {
    }

    public static function input(): string
    {
        return PairDocumentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof PairDocumentInput);

        return new OperationOutput($this->answers->row($this->writes->pair($input)), 'Paired the document with the planned occurrence.');
    }
}
