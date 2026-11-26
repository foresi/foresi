<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\CreateDealInput;
use Foresi\Sales\Application\DealWrites;
use Foresi\Sales\Contract\Field\DealExtension;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class CreateDealHandler implements OperationHandler
{
    public function __construct(private DealWrites $writes, private DealAnswer $answer, private CustomFieldPayload $customFields)
    {
    }

    public static function input(): string
    {
        return CreateDealInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateDealInput);

        return $this->answer->of($this->writes->create($input, null, $this->customFields->read(DealExtension::RECORD, $input->customFields, true))->id(), 'Opened the deal %s.', true);
    }
}
