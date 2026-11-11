<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mcp;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Puts every read tool of the modules into the MCP server's registry. The
 * annotations say each one only reads, the same answer every time, from
 * Foresi alone; they are hints for the client, the scope check decides.
 */
final readonly class ReadToolLoader implements LoaderInterface
{
    public const string NAME_PREFIX = 'foresi_';

    /**
     * @param iterable<ReadTool> $tools
     */
    public function __construct(
        #[AutowireIterator(ReadTool::TAG)]
        private iterable $tools,
        private ReadToolCall $call,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        foreach ($this->tools as $tool) {
            $name = $tool->name();
            if (!str_starts_with($name, self::NAME_PREFIX)) {
                throw new \LogicException(\sprintf('The MCP tool "%s" must be named with the prefix "%s".', $name, self::NAME_PREFIX));
            }
            if ($registry->hasTool($name)) {
                throw new \LogicException(\sprintf('Two MCP tools are named "%s".', $name));
            }

            $registry->registerTool(
                new Tool(
                    $name,
                    $tool->title(),
                    $tool->inputSchema(),
                    $tool->description(),
                    new ToolAnnotations($tool->title(), readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
                ),
                // The SDK hands a handler its arguments by parameter name; the
                // request context carries them whole, as the tool's schema has them.
                function (RequestContext $context) use ($tool): CallToolResult {
                    $request = $context->getRequest();

                    return ($this->call)($tool, $request instanceof CallToolRequest ? $request->arguments : []);
                },
            );
        }
    }
}
