<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Api\Operation;

use Foresi\Catalog\Application\CreateProductInput;
use Foresi\Catalog\Application\ProductWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class CreateProductHandler implements OperationHandler
{
    public function __construct(private ProductWrites $writes, private ProductAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateProductInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateProductInput);

        return $this->answer->of($this->writes->create($input), 'Added the product %s to the catalog.', true);
    }
}
