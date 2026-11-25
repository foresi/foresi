<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api\Operation;

use Foresi\Purchasing\Application\Api\ReceivedDocumentWrites;
use Foresi\Purchasing\Application\Api\RemoveReceivedDocumentPaymentInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * received_documents.remove_payment: the received document with its payments.
 */
final readonly class RemoveReceivedDocumentPaymentHandler implements OperationHandler
{
    public function __construct(private ReceivedDocumentWrites $writes, private ReceivedDocumentAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RemoveReceivedDocumentPaymentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RemoveReceivedDocumentPaymentInput);

        return $this->answer->of($this->writes->removePayment($input), 'Removed a payment of the received document %s.');
    }
}
