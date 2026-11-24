<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api\Operation;

use Foresi\Margin\Application\RebillLinks;
use Foresi\Margin\Application\RebillLinkView;
use Foresi\Margin\Application\SetRebillLinkInput;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * margin.rebill_links.set: the planned cost a template line rebills.
 */
final readonly class SetRebillLinkHandler implements OperationHandler
{
    public function __construct(private RebillLinks $links)
    {
    }

    public static function input(): string
    {
        return SetRebillLinkInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetRebillLinkInput);
        $view = $this->links->set($input->recurringLineId, $input->plannedItemId);

        return new OperationOutput(MarginJson::rebillLink($input->recurringLineId, $view), $view instanceof RebillLinkView ? 'The line rebills the planned cost.' : 'The line rebills no planned cost.');
    }
}
