<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Operation;

use App\Shared\Application\RefusalKind;
use App\Shared\Application\Refused;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;
use App\Shared\Infrastructure\Mcp\WriteTool;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The MCP tool of a declared operation: its schema from the input, its hints
 * from the declaration, and the same runner as REST. The call path of every
 * write tool checks the scope, spends the write budget and keeps the
 * idempotency key around it; a refusal comes back with the code of its kind,
 * invalid_argument for a value and the reason otherwise.
 */
#[Exclude]
final readonly class OperationTool implements WriteTool
{
    public function __construct(private OperationDefinition $definition, private OperationRunner $runner)
    {
    }

    public function name(): string
    {
        return $this->definition->operation->tool;
    }

    public function title(): string
    {
        return $this->definition->operation->title;
    }

    public function scope(): string
    {
        return $this->definition->operation->scope;
    }

    public function description(): string
    {
        return $this->definition->operation->description;
    }

    public function inputSchema(): array
    {
        return InputSchema::of($this->definition->fields);
    }

    public function isDestructive(): bool
    {
        return $this->definition->operation->destructive;
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $output = $this->runner->run($this->definition, $arguments);
        } catch (Refused $refused) {
            throw RefusalKind::Invalid === $refused->kind() ? new ToolRefusal(ToolRefusal::INVALID_ARGUMENT, $refused->getMessage(), ['reason' => $refused->reason()] + $refused->details()) : new ToolRefusal($refused->reason(), $refused->getMessage(), $refused->details());
        }

        return new ToolOutput($output->data, $output->summary);
    }
}
