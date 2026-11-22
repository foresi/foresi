<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\AttachSignedCopyInput;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

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
