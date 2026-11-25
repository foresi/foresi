<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\MarkDocumentSentInput;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class MarkDocumentSentHandler implements OperationHandler
{
    public function __construct(private DocumentWrites $writes, private DocumentsJson $json)
    {
    }

    public static function input(): string
    {
        return MarkDocumentSentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof MarkDocumentSentInput);

        return new OperationOutput($this->json->document($this->writes->markSent($input)), 'Marked the document sent for signature.');
    }
}
