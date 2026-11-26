<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Api\Operation;

use Foresi\Catalog\Application\ProductWrites;
use Foresi\Catalog\Application\UpdateProductInput;
use Foresi\Catalog\Contract\Field\ProductExtension;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UpdateProductHandler implements OperationHandler
{
    public function __construct(private ProductWrites $writes, private ProductAnswer $answer, private CustomFieldPayload $customFields)
    {
    }

    public static function input(): string
    {
        return UpdateProductInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateProductInput);

        return $this->answer->of($this->writes->update($input, $this->customFields->read(ProductExtension::RECORD, $input->customFields, false)), 'Updated the product %s.');
    }
}
