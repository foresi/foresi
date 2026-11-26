<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\InvoiceWrites;
use Foresi\Invoicing\Application\Api\TrashInvoiceInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * invoices.trash: the invoice in the trash.
 */
final readonly class TrashInvoiceHandler implements OperationHandler
{
    public function __construct(private InvoiceWrites $writes, private InvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return TrashInvoiceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof TrashInvoiceInput);

        return $this->answer->of(fn (): string => $this->writes->trash($input), 'Moved the invoice %s to the trash.');
    }
}
