<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Invoicing\Application\Api\UpdateRecurringInvoiceInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * recurring-invoices.update: the template with its lines and next issues.
 */
final readonly class UpdateRecurringInvoiceHandler implements OperationHandler
{
    public function __construct(private RecurringWrites $writes, private RecurringInvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateRecurringInvoiceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateRecurringInvoiceInput);

        return $this->answer->of(fn (): string => $this->writes->update($input), 'Changed the recurring invoice %s.');
    }
}
