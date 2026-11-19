<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\AppliedView;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Infrastructure\Api\WorkPage;

/**
 * What the read tools of Work share: a refusal of the use cases as MCP answers
 * it, and a page of a list with the saved view it went through.
 */
final readonly class WorkToolPages
{
    public function __construct(private ScopeGuard $scope)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     *
     * @throws ToolRefusal
     */
    public function read(\Closure $read, ?string $viewId = null): mixed
    {
        if (null !== $viewId && !$this->scope->allows(AppliedView::VIEWS_SCOPE)) {
            throw new ToolRefusal(ToolRefusal::INSUFFICIENT_SCOPE, \sprintf('The token does not have the "%s" scope a saved view needs.', AppliedView::VIEWS_SCOPE));
        }
        try {
            return $read();
        } catch (SavedViewRefused|WorkRefused $refused) {
            throw ToolRefusal::of($refused);
        }
    }

    public static function page(WorkPage $page, string $one, string $many, bool $withView = true): ToolOutput
    {
        $output = ToolArguments::page($page->items, $page->nextAfter, $one, $many);
        if (!$withView) {
            return $output;
        }
        if (null === $page->view) {
            return new ToolOutput($output->data + ['applied_view' => null], $output->summary);
        }
        $name = $page->view['name'] ?? '';

        return new ToolOutput($output->data + ['applied_view' => $page->view], \sprintf('Through the view %s: %s', \is_string($name) ? $name : '', $output->summary));
    }
}
