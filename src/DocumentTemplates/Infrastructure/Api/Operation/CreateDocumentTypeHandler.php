<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Infrastructure\Api\Operation;

use Foresi\DocumentTemplates\Application\Api\CreateDocumentTypeInput;
use Foresi\DocumentTemplates\Application\DocumentTypeWrites;
use Foresi\DocumentTemplates\Infrastructure\Api\DocumentsJson;
use Foresi\DocumentTemplates\Infrastructure\Api\DocumentsRoutes;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CreateDocumentTypeHandler implements OperationHandler
{
    public function __construct(private DocumentTypeWrites $writes, private DocumentsJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return CreateDocumentTypeInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateDocumentTypeInput);
        $type = $this->writes->create($input);

        return new OperationOutput($this->json->type($type), \sprintf('Created the document type %s.', $type->code()), $this->urls->generate(DocumentsRoutes::TYPES));
    }
}
