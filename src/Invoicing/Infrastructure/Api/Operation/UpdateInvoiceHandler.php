<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\InvoiceWrites;
use Foresi\Invoicing\Application\Api\UpdateInvoiceInput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * invoices.update: the invoice as changed.
 */
final readonly class UpdateInvoiceHandler implements OperationHandler
{
    public function __construct(private InvoiceWrites $writes, private InvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateInvoiceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateInvoiceInput);

        return $this->answer->of(fn (): string => $this->writes->update($input), 'Changed the invoice %s.');
    }
}
