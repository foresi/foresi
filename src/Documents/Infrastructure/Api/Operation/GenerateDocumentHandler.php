<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\GenerateDocumentInput;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class GenerateDocumentHandler implements OperationHandler
{
    public function __construct(private DocumentWrites $writes, private DocumentsJson $json)
    {
    }

    public static function input(): string
    {
        return GenerateDocumentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof GenerateDocumentInput);

        return new OperationOutput($this->json->document($this->writes->generate($input)), 'Generated the document: its PDF, values and template version are frozen.');
    }
}
