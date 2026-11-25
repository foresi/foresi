<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\WriteTool;

/**
 * A write tool that answers what it is given and remembers its arguments.
 */
final class FakeWriteTool implements WriteTool
{
    public const string SCOPE = 'things:write';

    /** @var array<string, mixed>|null */
    public ?array $arguments = null;
    public int $calls = 0;

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        private readonly string $name = 'foresi_fake',
        private readonly ?ToolOutput $output = null,
        private readonly ?\Throwable $failure = null,
        private readonly array $schema = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        private readonly bool $destructive = false,
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

    public function scope(): string
    {
        return self::SCOPE;
    }

    public function description(): string
    {
        return 'Answers what the test gives it.';
    }

    public function inputSchema(): array
    {
        return $this->schema;
    }

    public function isDestructive(): bool
    {
        return $this->destructive;
    }

    public function call(array $arguments): ToolOutput
    {
        $this->arguments = $arguments;
        ++$this->calls;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return $this->output ?? new ToolOutput([], 'Nothing.');
    }
}
