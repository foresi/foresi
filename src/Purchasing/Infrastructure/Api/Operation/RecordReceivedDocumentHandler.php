<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Api\Operation;

use App\Purchasing\Application\Api\ReceivedDocumentWrites;
use App\Purchasing\Application\Api\RecordReceivedDocumentInput;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

/**
 * received_documents.record: the received document with its payments.
 */
final readonly class RecordReceivedDocumentHandler implements OperationHandler
{
    public function __construct(private ReceivedDocumentWrites $writes, private ReceivedDocumentAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RecordReceivedDocumentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RecordReceivedDocumentInput);

        return $this->answer->of($this->writes->record($input), 'Recorded the received document %s.', created: true);
    }
}
