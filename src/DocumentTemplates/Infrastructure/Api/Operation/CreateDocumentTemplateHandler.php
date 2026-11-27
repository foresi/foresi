<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Infrastructure\Api\Operation;

use Foresi\DocumentTemplates\Application\Api\CreateDocumentTemplateInput;
use Foresi\DocumentTemplates\Application\DocumentsStore;
use Foresi\DocumentTemplates\Application\TemplateWrites;
use Foresi\DocumentTemplates\Infrastructure\Api\DocumentsJson;
use Foresi\DocumentTemplates\Infrastructure\Api\DocumentsRoutes;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CreateDocumentTemplateHandler implements OperationHandler
{
    public function __construct(private TemplateWrites $writes, private DocumentsJson $json, private UrlGeneratorInterface $urls, private DocumentsStore $store)
    {
    }

    public static function input(): string
    {
        return CreateDocumentTemplateInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateDocumentTemplateInput);
        $version = $this->writes->create($input);
        $template = $this->store->template($version->templateId()) ?? throw new \LogicException('The template was just created.');

        return new OperationOutput($this->json->template($template), 'Created the template with its first version.', $this->urls->generate(DocumentsRoutes::TEMPLATE, ['templateId' => $template->id()]));
    }
}
