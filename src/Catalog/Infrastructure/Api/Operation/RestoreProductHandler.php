<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Api\Operation;

use Foresi\Catalog\Application\ProductWrites;
use Foresi\Catalog\Application\RestoreProductInput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class RestoreProductHandler implements OperationHandler
{
    public function __construct(private ProductWrites $writes, private ProductAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RestoreProductInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RestoreProductInput);

        return $this->answer->of($this->writes->restore($input), 'Restored the product %s.');
    }
}
