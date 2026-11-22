<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\UpdateDocumentTypeInput;
use Foresi\Documents\Application\DocumentTypeWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class UpdateDocumentTypeHandler implements OperationHandler
{
    public function __construct(private DocumentTypeWrites $writes, private DocumentsJson $json)
    {
    }

    public static function input(): string
    {
        return UpdateDocumentTypeInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateDocumentTypeInput);
        $type = $this->writes->update($input);

        return new OperationOutput($this->json->type($type), \sprintf('Changed the document type %s.', $type->code()));
    }
}
