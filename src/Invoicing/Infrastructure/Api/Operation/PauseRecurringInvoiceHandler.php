<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\PauseRecurringInvoiceInput;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * recurring-invoices.pause: the template with its lines and next issues.
 */
final readonly class PauseRecurringInvoiceHandler implements OperationHandler
{
    public function __construct(private RecurringWrites $writes, private RecurringInvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return PauseRecurringInvoiceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof PauseRecurringInvoiceInput);

        return $this->answer->of(fn (): string => $this->writes->pause($input), 'Paused the recurring invoice %s.');
    }
}
