<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Infrastructure\Api\Operation;

use Foresi\DocumentTemplates\Application\Api\AttachSignedCopyInput;
use Foresi\DocumentTemplates\Application\DocumentWrites;
use Foresi\DocumentTemplates\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class AttachSignedCopyHandler implements OperationHandler
{
    public function __construct(private DocumentWrites $writes, private DocumentsJson $json)
    {
    }

    public static function input(): string
    {
        return AttachSignedCopyInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AttachSignedCopyInput);
        $document = $this->writes->attachSignedCopy($input);

        return new OperationOutput($this->json->document($document), \sprintf('Attached the signed copy; the document is %s.', $document->status()->value));
    }
}
