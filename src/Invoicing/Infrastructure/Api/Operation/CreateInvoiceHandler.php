<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\CreateInvoiceInput;
use Foresi\Invoicing\Application\Api\InvoiceWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * invoices.create: the invoice with its lines, payments and balances.
 */
final readonly class CreateInvoiceHandler implements OperationHandler
{
    public function __construct(private InvoiceWrites $writes, private InvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateInvoiceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateInvoiceInput);

        return $this->answer->of(fn (): string => $this->writes->create($input), 'Issued the invoice %s.', created: true);
    }
}
