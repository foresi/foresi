<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Api\Operation;

use App\Purchasing\Application\Api\ReceivedDocumentWrites;
use App\Purchasing\Application\Api\TrashReceivedDocumentInput;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

/**
 * received_documents.trash: the received document with its payments.
 */
final readonly class TrashReceivedDocumentHandler implements OperationHandler
{
    public function __construct(private ReceivedDocumentWrites $writes, private ReceivedDocumentAnswer $answer)
    {
    }

    public static function input(): string
    {
        return TrashReceivedDocumentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof TrashReceivedDocumentInput);

        return $this->answer->of($this->writes->trash($input), 'Moved the received document %s to the trash.');
    }
}
