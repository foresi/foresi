<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api\Operation;

use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\SetSupplierDefaultInput;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * margin.supplier_defaults.set: the versions of the default of the supplier.
 */
final readonly class SetSupplierDefaultHandler implements OperationHandler
{
    public function __construct(private AllocationWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetSupplierDefaultInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetSupplierDefaultInput);

        return new OperationOutput(MarginJson::supplierDefault($this->writes->setSupplierDefaultRecipient($input)), null === $input->recipient ? 'Ended the default recipient of the supplier.' : 'Set the default recipient of the supplier.');
    }
}
