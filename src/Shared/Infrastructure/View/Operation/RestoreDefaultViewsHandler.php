<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View\Operation;

use Foresi\Shared\Application\View\RestoreDefaultViewsInput;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;

final readonly class RestoreDefaultViewsHandler implements OperationHandler
{
    public function __construct(private SavedViews $views, private ViewJson $json)
    {
    }

    public static function input(): string
    {
        return RestoreDefaultViewsInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RestoreDefaultViewsInput);
        $restored = $this->views->restoreDefaults($input);

        return new OperationOutput(
            ['views' => array_map($this->json->view(...), $restored)],
            \sprintf('Restored the %d default views of %s.', \count($restored), $input->listKey),
        );
    }
}
