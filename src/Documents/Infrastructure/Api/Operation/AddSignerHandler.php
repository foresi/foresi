<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api\Operation;

use Foresi\Documents\Application\Api\AddSignerInput;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class AddSignerHandler implements OperationHandler
{
    public function __construct(private DocumentWrites $writes, private DocumentsJson $json)
    {
    }

    public static function input(): string
    {
        return AddSignerInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddSignerInput);
        $signer = $this->writes->addSigner($input);

        return new OperationOutput($this->json->signer($signer), 'Added the signer.');
    }
}
