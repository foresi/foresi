<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api\Operation;

use Foresi\Purchasing\Application\Api\ReceivedDocumentWrites;
use Foresi\Purchasing\Application\Api\UploadReceivedDocumentInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * received_documents.upload: the draft made from the file, its reading queued.
 */
final readonly class UploadReceivedDocumentHandler implements OperationHandler
{
    public function __construct(private ReceivedDocumentWrites $writes, private ReceivedDocumentAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UploadReceivedDocumentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UploadReceivedDocumentInput);

        return $this->answer->of($this->writes->upload($input), 'Made a draft of %s from the file; it is being read.', created: true);
    }
}
