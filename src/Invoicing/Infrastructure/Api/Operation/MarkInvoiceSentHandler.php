<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api\Operation;

use Foresi\Invoicing\Application\Api\InvoiceWrites;
use Foresi\Invoicing\Application\Api\MarkInvoiceSentInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * invoices.mark_sent: the invoice as marked.
 */
final readonly class MarkInvoiceSentHandler implements OperationHandler
{
    public function __construct(private InvoiceWrites $writes, private InvoiceAnswer $answer)
    {
    }

    public static function input(): string
    {
        return MarkInvoiceSentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof MarkInvoiceSentInput);

        return $this->answer->of(fn (): string => $this->writes->markSent($input), 'Marked the invoice %s as sent.');
    }
}
