<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Mcp;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A tool an MCP client may call to change data. It is built like a ReadTool,
 * but is published with the annotation readOnlyHint false and needs the write
 * scope (tag "foresi.shared.mcp_write_tool"). A write tool calls the use cases of its
 * module and nothing else, so what a token's person may do is decided there,
 * as it is for the REST API.
 */
#[AutoconfigureTag(self::TAG)]
interface WriteTool
{
    public const string TAG = 'foresi.shared.mcp_write_tool';

    /**
     * Unique, in snake case, starting with the prefix of the tool's module:
     * "foresi_" for Foresi's own, such as "foresi_create_webhook_endpoint", and the module id
     * in snake case for any other vendor's, such as "acme_fleet_add_vehicle".
     */
    public function name(): string;

    /**
     * A short name for people, such as "Create a webhook endpoint".
     */
    public function title(): string;

    /**
     * The scope a token needs to see and call the tool, such as "webhooks:write"
     * (ADR 0032); ScopeGuard::ANY for a tool every token may call.
     */
    public function scope(): string;

    /**
     * What the tool changes and when to use it, written for the model.
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
     * True when the call destroys something or cannot be taken back, such as a
     * delete or a rotation that retires a secret: the client may ask for a
     * confirmation first (annotation destructiveHint).
     */
    public function isDestructive(): bool;

    /**
     * Runs as the token's person in the token's organisation. The arguments are
     * already valid against the input schema.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws ToolRefusal when the call cannot be done, such as an unknown id
     */
    public function call(array $arguments): ToolOutput;
}
