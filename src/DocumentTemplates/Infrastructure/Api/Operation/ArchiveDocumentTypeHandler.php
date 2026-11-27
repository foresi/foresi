<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Infrastructure\Api\Operation;

use Foresi\DocumentTemplates\Application\Api\ArchiveDocumentTypeInput;
use Foresi\DocumentTemplates\Application\DocumentTypeWrites;
use Foresi\DocumentTemplates\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class ArchiveDocumentTypeHandler implements OperationHandler
{
    public function __construct(private DocumentTypeWrites $writes, private DocumentsJson $json)
    {
    }

    public static function input(): string
    {
        return ArchiveDocumentTypeInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ArchiveDocumentTypeInput);
        $type = $this->writes->archive($input);

        return new OperationOutput($this->json->type($type), \sprintf('Archived the document type %s.', $type->code()));
    }
}
