<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Mcp\ReadToolCall;
use Foresi\Shared\Infrastructure\Mcp\ReadToolLoader;
use Mcp\Capability\Registry;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

/**
 * Every read tool lands in the SDK registry as a read-only tool whose handler
 * goes through the shared call.
 */
final class ReadToolLoaderTest extends TestCase
{
    public function testEachToolIsRegisteredAsReadOnly(): void
    {
        $registry = new Registry();
        $schema = ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']];

        $this->loader(new FakeReadTool('foresi_one', schema: $schema), new FakeReadTool('foresi_two'))->load($registry);

        $tool = $registry->getTool('foresi_one')->tool;
        self::assertSame('foresi_one', $tool->name);
        self::assertSame('Fake tool', $tool->title);
        self::assertSame('Answers what the test gives it.', $tool->description);
        self::assertSame($schema, $tool->inputSchema);
        self::assertEquals(new ToolAnnotations('Fake tool', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false), $tool->annotations);
        self::assertTrue($registry->hasTool('foresi_two'));
    }

    public function testEachWriteToolIsRegisteredAsNotReadOnly(): void
    {
        $registry = new Registry();
        $loader = new ReadToolLoader([new FakeReadTool('foresi_read')], ToolCalls::create(), [
            new FakeWriteTool('foresi_change'),
            new FakeWriteTool('foresi_delete', destructive: true),
        ]);

        $loader->load($registry);

        self::assertEquals(new ToolAnnotations('Fake tool', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false), $registry->getTool('foresi_read')->tool->annotations);
        self::assertEquals(new ToolAnnotations('Fake tool', readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), $registry->getTool('foresi_change')->tool->annotations);
        self::assertEquals(new ToolAnnotations('Fake tool', readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false), $registry->getTool('foresi_delete')->tool->annotations);
    }

    public function testAWriteToolOfAReadToolsNameIsRefused(): void
    {
        $loader = new ReadToolLoader([new FakeReadTool('foresi_one')], ToolCalls::create(), [new FakeWriteTool('foresi_one')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Two MCP tools are named "foresi_one".');

        $loader->load(new Registry());
    }

    public function testTheHandlerPassesTheArgumentsOfTheRequest(): void
    {
        $registry = new Registry();
        $tool = new FakeReadTool(output: new ToolOutput(['ok' => true], 'Fine.'));
        $this->loader($tool)->load($registry);
        $handler = $registry->getTool('foresi_fake')->handler;
        self::assertInstanceOf(\Closure::class, $handler);

        $result = $handler(new RequestContext(self::createStub(SessionInterface::class), new CallToolRequest('foresi_fake', ['id' => 'a1'])));

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertFalse($result->isError);
        self::assertSame(['id' => 'a1'], $tool->arguments);
        self::assertSame(['data' => ['ok' => true], 'untrusted_text_notice' => ReadToolCall::UNTRUSTED_TEXT_NOTICE], $result->structuredContent);
    }

    public function testAnotherRequestCallsTheToolWithoutArguments(): void
    {
        $registry = new Registry();
        $tool = new FakeReadTool();
        $this->loader($tool)->load($registry);
        $handler = $registry->getTool('foresi_fake')->handler;
        self::assertInstanceOf(\Closure::class, $handler);

        $handler(new RequestContext(self::createStub(SessionInterface::class), new PingRequest()));

        self::assertSame([], $tool->arguments);
    }

    public function testAToolOutsideTheFamilyIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The MCP tool "whoami" must be named with the prefix "foresi_".');

        $this->loader(new FakeReadTool('whoami'))->load(new Registry());
    }

    public function testAToolOfAnotherVendorsModuleIsNamedAfterTheModule(): void
    {
        $registry = new Registry();

        new ReadToolLoader([new FakeReadTool('acme_tools_list')], ToolCalls::create(), modules: $this->acmeTools())->load($registry);

        self::assertTrue($registry->hasTool('acme_tools_list'));
    }

    public function testAToolOfAnotherVendorsModuleCannotTakeForesisPrefix(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The MCP tool "foresi_list" must be named with the prefix "acme_tools_".');

        new ReadToolLoader([new FakeReadTool('foresi_list')], ToolCalls::create(), modules: $this->acmeTools())->load(new Registry());
    }

    public function testAToolOfForesisOwnModuleTakesForesisPrefix(): void
    {
        $graph = new ModuleGraph([new ModuleManifest(ModuleId::of('mcp'), ModuleKind::Module, 'foresi/mcp', __NAMESPACE__.'\\', [], [])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The MCP tool "acme_list" must be named with the prefix "foresi_".');

        new ReadToolLoader([new FakeReadTool('acme_list')], ToolCalls::create(), modules: $graph)->load(new Registry());
    }

    public function testTwoToolsOfOneNameAreRefused(): void
    {
        $registry = new Registry();

        try {
            $this->loader(new FakeReadTool('foresi_one'), new FakeReadTool('foresi_one'))->load($registry);
            self::fail('A second tool of the same name was registered.');
        } catch (\LogicException $refused) {
            self::assertSame('Two MCP tools are named "foresi_one".', $refused->getMessage());
        }
    }

    /**
     * A module of another vendor that the fake tools belong to.
     */
    private function acmeTools(): ModuleGraph
    {
        return new ModuleGraph([new ModuleManifest(ModuleId::of('acme-tools'), ModuleKind::Module, 'acme/tools', __NAMESPACE__.'\\', [], [])]);
    }

    private function loader(FakeReadTool ...$tools): ReadToolLoader
    {
        return new ReadToolLoader($tools, ToolCalls::create());
    }
}
