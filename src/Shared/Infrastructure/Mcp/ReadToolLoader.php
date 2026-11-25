<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\WriteTool;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleNamespace;
use Foresi\Shared\Infrastructure\Api\Idempotency;
use Foresi\Shared\Infrastructure\Operation\OperationRunner;
use Foresi\Shared\Infrastructure\Operation\Operations;
use Foresi\Shared\Infrastructure\Operation\OperationTool;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Puts every read and write tool of the modules into the MCP server's registry.
 * A tool's name starts with the prefix of its module, found by the tool's class
 * (an operation's by its input class): "foresi_" for the packages foresi/*,
 * the module id in snake case for others, such as "acme_fleet_" (ADR 0044).
 * The annotations of a read tool say it only reads, the same answer every time,
 * from Foresi alone; a write tool says it changes things, and whether it
 * destroys, and takes an idempotency_key. They are hints for the client, the scope check decides.
 */
final readonly class ReadToolLoader implements LoaderInterface
{
    /** The prefix of a tool of no installed module, such as a test's. */
    public const string NAME_PREFIX = ModuleNamespace::OWN_VENDOR.'_';

    /**
     * @param iterable<ReadTool>  $tools
     * @param iterable<WriteTool> $writeTools
     */
    public function __construct(
        #[AutowireIterator(ReadTool::TAG)]
        private iterable $tools,
        private ReadToolCall $call,
        #[AutowireIterator(WriteTool::TAG)]
        private iterable $writeTools = [],
        private ?Operations $operations = null,
        private ?OperationRunner $runner = null,
        private ?ModuleGraph $modules = null,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        foreach ($this->tools as $tool) {
            $this->register($registry, $tool, new ToolAnnotations($tool->title(), readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false));
        }
        foreach ($this->writeTools() as $tool) {
            $this->register($registry, $tool, new ToolAnnotations($tool->title(), readOnlyHint: false, destructiveHint: $tool->isDestructive(), idempotentHint: false, openWorldHint: false));
        }
    }

    /**
     * The scope each tool needs, by tool name, for listing only what a token may call.
     *
     * @return array<string, string>
     */
    public function scopes(): array
    {
        $scopes = [];
        foreach ([...$this->tools, ...$this->writeTools()] as $tool) {
            $scopes[$tool->name()] = $tool->scope();
        }

        return $scopes;
    }

    /**
     * The tools that are for the organisation's own people only (ADR 0041), by name.
     *
     * @return array<string, true>
     */
    public function staffOnly(): array
    {
        $names = [];
        foreach ([...$this->tools, ...$this->writeTools()] as $tool) {
            if ($tool instanceof StaffOnlyTool && $tool->staffOnly()) {
                $names[$tool->name()] = true;
            }
        }

        return $names;
    }

    /**
     * The write tools of the modules and one for each declared operation (ADR 0032).
     *
     * @return list<WriteTool>
     */
    private function writeTools(): array
    {
        $tools = [...$this->writeTools];
        if ($this->operations instanceof Operations && $this->runner instanceof OperationRunner) {
            foreach ($this->operations->all() as $definition) {
                $tools[] = new OperationTool($definition, $this->runner);
            }
        }

        return array_values($tools);
    }

    /**
     * Every write tool takes an optional idempotency_key (ADR 0032); MCP has no
     * standard for one, so it is an argument.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function withIdempotencyKey(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $properties = $properties instanceof \stdClass ? (array) $properties : (\is_array($properties) ? $properties : []);
        $properties[Idempotency::ARGUMENT] = [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 255,
            'description' => 'Optional. A unique string you choose per change, such as a UUID. Send the same key when you retry the same call after a timeout or an error: Foresi then makes the change once and answers what the first call answered. Never reuse a key for a different call.',
        ];
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * The prefix of the module the tool belongs to; of the longest namespace, should one module's sit in another's.
     */
    private function prefixOf(ReadTool|WriteTool $tool): string
    {
        $class = $tool instanceof OperationTool ? $tool->input() : $tool::class;
        $owner = null;
        foreach ($this->modules?->all() ?? [] as $module) {
            if ($module->owns($class) && \strlen($module->namespace) > \strlen($owner->namespace ?? '')) {
                $owner = $module;
            }
        }

        return $owner?->toolPrefix() ?? self::NAME_PREFIX;
    }

    private function register(RegistryInterface $registry, ReadTool|WriteTool $tool, ToolAnnotations $annotations): void
    {
        $name = $tool->name();
        $prefix = $this->prefixOf($tool);
        if (!str_starts_with($name, $prefix)) {
            throw new \LogicException(\sprintf('The MCP tool "%s" must be named with the prefix "%s".', $name, $prefix));
        }
        if ($registry->hasTool($name)) {
            throw new \LogicException(\sprintf('Two MCP tools are named "%s".', $name));
        }

        $schema = $tool->inputSchema();
        if ($tool instanceof WriteTool) {
            $schema = $this->withIdempotencyKey($schema);
        }
        $registry->registerTool(
            new Tool($name, $tool->title(), $schema, $tool->description(), $annotations),
            // The SDK hands a handler its arguments by parameter name; the
            // request context carries them whole, as the tool's schema has them.
            function (RequestContext $context) use ($tool): CallToolResult {
                $request = $context->getRequest();

                return ($this->call)($tool, $request instanceof CallToolRequest ? $request->arguments : []);
            },
        );
    }
}
