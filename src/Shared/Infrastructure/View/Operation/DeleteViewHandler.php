<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View\Operation;

use Foresi\Shared\Application\View\DeleteViewInput;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class DeleteViewHandler implements OperationHandler
{
    public function __construct(private SavedViews $views)
    {
    }

    public static function input(): string
    {
        return DeleteViewInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof DeleteViewInput);

        return new OperationOutput(['id' => $this->views->delete($input), 'deleted' => true], 'Deleted the view.');
    }
}
