<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\InvoiceWrites;
use Foresi\Invoicing\Application\Api\RestoreInvoiceInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * invoices.restore: the invoice as restored.
 */
final readonly class RestoreInvoiceHandler implements OperationHandler
{
    public function __construct(private InvoiceWrites $writes, private InvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RestoreInvoiceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RestoreInvoiceInput);

        return $this->answer->of(fn (): string => $this->writes->restore($input), 'Restored the invoice %s from the trash.');
    }
}
