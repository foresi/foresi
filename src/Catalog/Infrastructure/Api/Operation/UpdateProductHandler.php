<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Api\Operation;

use Foresi\Catalog\Application\ProductWrites;
use Foresi\Catalog\Application\UpdateProductInput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class UpdateProductHandler implements OperationHandler
{
    public function __construct(private ProductWrites $writes, private ProductAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateProductInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateProductInput);

        return $this->answer->of($this->writes->update($input), 'Updated the product %s.');
    }
}
