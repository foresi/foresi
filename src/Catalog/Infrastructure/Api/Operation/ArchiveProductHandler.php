<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Api\Operation;

use Foresi\Catalog\Application\ArchiveProductInput;
use Foresi\Catalog\Application\ProductWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class ArchiveProductHandler implements OperationHandler
{
    public function __construct(private ProductWrites $writes, private ProductAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ArchiveProductInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ArchiveProductInput);

        return $this->answer->of($this->writes->archive($input), 'Archived the product %s.');
    }
}
