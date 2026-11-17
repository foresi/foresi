<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Api\Operation;

use App\Purchasing\Application\Api\AddReceivedDocumentPaymentInput;
use App\Purchasing\Application\Api\ReceivedDocumentWrites;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

/**
 * received_documents.add_payment: the received document with its payments.
 */
final readonly class AddReceivedDocumentPaymentHandler implements OperationHandler
{
    public function __construct(private ReceivedDocumentWrites $writes, private ReceivedDocumentAnswer $answer)
    {
    }

    public static function input(): string
    {
        return AddReceivedDocumentPaymentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddReceivedDocumentPaymentInput);

        return $this->answer->of($this->writes->addPayment($input), 'Recorded a payment of the received document %s.', created: true);
    }
}
