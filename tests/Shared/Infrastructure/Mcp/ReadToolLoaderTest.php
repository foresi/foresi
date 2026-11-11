<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use App\Shared\Infrastructure\Mcp\ReadToolCall;
use App\Shared\Infrastructure\Mcp\ReadToolLoader;
use App\Shared\Infrastructure\Mcp\ToolOutput;
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

    private function loader(FakeReadTool ...$tools): ReadToolLoader
    {
        return new ReadToolLoader($tools, new ReadToolCall(new FakeCaller(), new FakeScopes(), new RecordingLogger()));
    }
}
