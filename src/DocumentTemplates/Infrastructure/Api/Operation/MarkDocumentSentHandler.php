<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Infrastructure\Api\Operation;

use Foresi\DocumentTemplates\Application\Api\MarkDocumentSentInput;
use Foresi\DocumentTemplates\Application\DocumentWrites;
use Foresi\DocumentTemplates\Infrastructure\Api\DocumentsJson;
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
