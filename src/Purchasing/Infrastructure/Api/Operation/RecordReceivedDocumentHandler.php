<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api\Operation;

use Foresi\Purchasing\Application\Api\ReceivedDocumentWrites;
use Foresi\Purchasing\Application\Api\RecordReceivedDocumentInput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

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
