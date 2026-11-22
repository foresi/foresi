<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\SaveTemplateVersionInput;
use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Application\TemplateWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class SaveTemplateVersionHandler implements OperationHandler
{
    public function __construct(private TemplateWrites $writes, private DocumentsJson $json, private DocumentsStore $store)
    {
    }

    public static function input(): string
    {
        return SaveTemplateVersionInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SaveTemplateVersionInput);
        $version = $this->writes->saveVersion($input);
        $template = $this->store->template($version->templateId()) ?? throw new \LogicException('The template exists.');

        return new OperationOutput($this->json->template($template), \sprintf('Saved version %d of the template.', $version->number()));
    }
}
