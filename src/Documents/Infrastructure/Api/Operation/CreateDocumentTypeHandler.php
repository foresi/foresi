<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\CreateDocumentTypeInput;
use Foresi\Documents\Application\DocumentTypeWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Documents\Infrastructure\Api\DocumentsRoutes;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
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
