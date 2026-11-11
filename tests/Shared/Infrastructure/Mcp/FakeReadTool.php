<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolOutput;

/**
 * A read tool that answers what it is given and remembers its arguments.
 */
final class FakeReadTool implements ReadTool
{
    /** @var array<string, mixed>|null */
    public ?array $arguments = null;

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        private readonly string $name = 'foresi_fake',
        private readonly ?ToolOutput $output = null,
        private readonly ?\Throwable $failure = null,
        private readonly array $schema = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return 'Fake tool';
    }

    public function description(): string
    {
        return 'Answers what the test gives it.';
    }

    public function inputSchema(): array
    {
        return $this->schema;
    }

    public function call(array $arguments): ToolOutput
    {
        $this->arguments = $arguments;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return $this->output ?? new ToolOutput([], 'Nothing.');
    }
}
