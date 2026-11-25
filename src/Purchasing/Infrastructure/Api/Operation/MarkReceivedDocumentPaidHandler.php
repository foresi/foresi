<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api\Operation;

use Foresi\Purchasing\Application\Api\MarkReceivedDocumentPaidInput;
use Foresi\Purchasing\Application\Api\ReceivedDocumentWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * received_documents.mark_paid: the received document with its payments.
 */
final readonly class MarkReceivedDocumentPaidHandler implements OperationHandler
{
    public function __construct(private ReceivedDocumentWrites $writes, private ReceivedDocumentAnswer $answer)
    {
    }

    public static function input(): string
    {
        return MarkReceivedDocumentPaidInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof MarkReceivedDocumentPaidInput);

        return $this->answer->of($this->writes->markPaid($input), 'Marked the received document %s as paid.');
    }
}
