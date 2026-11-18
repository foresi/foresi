<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View\Operation;

use Foresi\Shared\Application\View\CreateViewInput;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Shared\Infrastructure\View\Api\ViewApiController;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CreateViewHandler implements OperationHandler
{
    public function __construct(private SavedViews $views, private ViewJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return CreateViewInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateViewInput);
        $view = $this->views->create($input);

        return new OperationOutput($this->json->view($view), \sprintf('Created the %s view %s on %s.', $view->visibility()->value, $view->name(), $view->listKey()), $this->urls->generate(ViewApiController::VIEW, ['viewId' => $view->id()]));
    }
}
