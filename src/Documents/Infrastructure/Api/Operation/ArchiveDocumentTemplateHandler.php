<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\ArchiveDocumentTemplateInput;
use Foresi\Documents\Application\TemplateWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class ArchiveDocumentTemplateHandler implements OperationHandler
{
    public function __construct(private TemplateWrites $writes, private DocumentsJson $json)
    {
    }

    public static function input(): string
    {
        return ArchiveDocumentTemplateInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ArchiveDocumentTemplateInput);
        $template = $this->writes->archive($input);

        return new OperationOutput($this->json->template($template), 'Archived the template.');
    }
}
