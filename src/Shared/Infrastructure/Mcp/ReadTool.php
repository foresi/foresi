<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mcp;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A tool an MCP client may call to read data. Modules implement it in
 * <Module>/Infrastructure/Mcp and never touch the MCP SDK: the adapter
 * publishes every implementation (tag "app.mcp_read_tool"), checks the read
 * scope before each call and marks the output as data.
 */
#[AutoconfigureTag(self::TAG)]
interface ReadTool
{
    public const string TAG = 'app.mcp_read_tool';

    /**
     * Unique, in snake case, starting with "foresi_", such as "foresi_search_invoices".
     */
    public function name(): string;

    /**
     * A short name for people, such as "Search invoices".
     */
    public function title(): string;

    /**
     * What the tool returns and when to use it, written for the model.
     */
    public function description(): string;

    /**
     * The JSON Schema of the arguments, an object schema such as
     * ['type' => 'object', 'properties' => [...], 'required' => [...]].
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Runs as the token's person in the token's organisation. The arguments are
     * already valid against the input schema.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws ToolRefusal when the call cannot be answered, such as an unknown id
     */
    public function call(array $arguments): ToolOutput;
}
