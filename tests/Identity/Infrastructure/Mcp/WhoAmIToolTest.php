<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Mcp;

use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\ApiTokenGrant;
use App\Identity\Application\Query\CurrentApiIdentity;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Mcp\WhoAmITool;
use App\Shared\Infrastructure\Mcp\ToolRefusal;
use PHPUnit\Framework\TestCase;

/**
 * The whoami tool answers what GET /api/v1/me answers, and takes no arguments.
 */
final class WhoAmIToolTest extends TestCase
{
    public function testItDescribesItselfAsAFamilyToolWithoutArguments(): void
    {
        $tool = $this->tool(null);

        self::assertSame('foresi_whoami', $tool->name());
        self::assertSame('Who am I', $tool->title());
        self::assertStringStartsWith('Returns the person this connection acts as', $tool->description());
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false], $tool->inputSchema());
    }

    public function testItAnswersThePersonAndToken(): void
    {
        $user = new User('testing@example.com', 'hash', 'Testing');
        $grant = new ApiTokenGrant($user->id(), '019a0000-0000-7000-8000-0000000000aa', 'Claude', [ApiScope::read(), ApiScope::write()], new \DateTimeImmutable('2026-12-01T10:00:00+01:00'));

        $output = $this->tool($grant, $user)->call([]);

        self::assertSame([
            'user' => ['id' => $user->id(), 'email' => 'testing@example.com'],
            'token' => ['id' => '019a0000-0000-7000-8000-0000000000aa', 'name' => 'Claude', 'scopes' => ['read', 'write'], 'expires_at' => '2026-12-01T10:00:00+01:00'],
        ], $output->data);
        self::assertSame('The person and API token of this connection.', $output->summary);
    }

    public function testWithoutAReadingTokenItRefuses(): void
    {
        try {
            $this->tool(null)->call([]);
            self::fail('The tool answered without a token.');
        } catch (ToolRefusal $refused) {
            self::assertSame('insufficient_scope', $refused->errorCode);
            self::assertSame('The connection has no valid token.', $refused->getMessage());
        }
    }

    private function tool(?ApiTokenGrant $grant, ?User $user = null): WhoAmITool
    {
        $tokens = self::createStub(ApiTokenContext::class);
        if ($grant instanceof ApiTokenGrant) {
            $tokens->method('grant')->willReturn($grant);
        } else {
            $tokens->method('grant')->willReturn(null);
        }
        $users = self::createStub(UserRepository::class);
        $users->method('findById')->willReturn($user);

        return new WhoAmITool(new CurrentApiIdentity($tokens, $users));
    }
}
