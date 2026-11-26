<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api\Operation;

use Foresi\Purchasing\Application\Api\ReadReceivedDocumentAgainInput;
use Foresi\Purchasing\Application\Api\ReceivedDocumentWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * received_documents.read_again: the draft, with its reading asked for again.
 */
final readonly class ReadReceivedDocumentAgainHandler implements OperationHandler
{
    public function __construct(private ReceivedDocumentWrites $writes, private ReceivedDocumentAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ReadReceivedDocumentAgainInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ReadReceivedDocumentAgainInput);

        return $this->answer->of($this->writes->readAgain($input), 'Queued the reading of the received document %s again.');
    }
}
