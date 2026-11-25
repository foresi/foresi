<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View\Operation;

use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\UpdateViewInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;

final readonly class UpdateViewHandler implements OperationHandler
{
    public function __construct(private SavedViews $views, private ViewJson $json)
    {
    }

    public static function input(): string
    {
        return UpdateViewInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateViewInput);
        $data = $this->json->view($this->views->update($input));

        return new OperationOutput($data, \sprintf('Changed the view %s.', \is_string($data['name']) ? $data['name'] : ''));
    }
}
