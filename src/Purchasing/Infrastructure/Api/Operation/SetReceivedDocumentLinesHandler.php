<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api\Operation;

use Foresi\Purchasing\Application\Api\ReceivedDocumentWrites;
use Foresi\Purchasing\Application\Api\SetReceivedDocumentLinesInput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * received_documents.lines.set: the received document with its lines and payments.
 */
final readonly class SetReceivedDocumentLinesHandler implements OperationHandler
{
    public function __construct(private ReceivedDocumentWrites $writes, private ReceivedDocumentAnswer $answer)
    {
    }

    public static function input(): string
    {
        return SetReceivedDocumentLinesInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetReceivedDocumentLinesInput);

        return $this->answer->of($this->writes->setLines($input), 'Set the lines of the received document %s.');
    }
}
