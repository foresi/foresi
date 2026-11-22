<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\MarkSignerDeclinedInput;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class MarkSignerDeclinedHandler implements OperationHandler
{
    public function __construct(private DocumentWrites $writes, private DocumentsJson $json)
    {
    }

    public static function input(): string
    {
        return MarkSignerDeclinedInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof MarkSignerDeclinedInput);

        return new OperationOutput($this->json->document($this->writes->markDeclined($input)), 'Marked the signer declined; the document is declined.');
    }
}
