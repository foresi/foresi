<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\CreateRecurringInvoiceInput;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * recurring-invoices.create: the template with its lines and next issues.
 */
final readonly class CreateRecurringInvoiceHandler implements OperationHandler
{
    public function __construct(private RecurringWrites $writes, private RecurringInvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateRecurringInvoiceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateRecurringInvoiceInput);

        return $this->answer->of(fn (): string => $this->writes->create($input), 'Created the recurring invoice %s.', created: true);
    }
}
