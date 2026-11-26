<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\Inbox;

use Foresi\Deals\Application\Api\CreateDealInput;
use Foresi\Deals\Application\DealWrites;
use Foresi\Deals\Infrastructure\Api\Operation\DealAnswer;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * inbox.actions.create_deal: the deal sales.deals.create opens, answered the same way.
 */
final readonly class CreateDealFromEmailHandler implements OperationHandler
{
    public function __construct(private DealWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateDealFromEmailInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateDealFromEmailInput);

        return $this->answer->of($this->writes->create(new CreateDealInput(
            title: $input->title,
            clientPartyId: $input->clientPartyId,
            description: $input->description,
        ))->id(), 'Opened the deal %s from the e-mail.', true);
    }
}
