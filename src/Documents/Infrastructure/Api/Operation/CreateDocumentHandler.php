<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\CreateDocumentInput;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Documents\Infrastructure\Api\DocumentsRoutes;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CreateDocumentHandler implements OperationHandler
{
    public function __construct(private DocumentWrites $writes, private DocumentsJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return CreateDocumentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateDocumentInput);
        $document = $this->writes->create($input);

        return new OperationOutput($this->json->document($document), 'Created the draft document.', $this->urls->generate(DocumentsRoutes::DOCUMENT, ['documentId' => $document->id()]));
    }
}
