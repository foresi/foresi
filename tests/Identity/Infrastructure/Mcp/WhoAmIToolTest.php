<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Mcp;

use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\ApiTokenGrant;
use App\Identity\Application\InsufficientScope;
use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\Organisation;
use App\Identity\Application\Query\CurrentApiIdentity;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Mcp\WhoAmITool;
use App\Shared\Infrastructure\Mcp\ToolRefusal;
use App\Tenancy\Domain\TenantId;
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
        self::assertStringStartsWith('Returns the person and the organisation', $tool->description());
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false], $tool->inputSchema());
    }

    public function testItAnswersThePersonOrganisationAndToken(): void
    {
        $user = new User('testing@example.com', 'hash', 'Testing');
        $tenant = TenantId::generate();
        $grant = new ApiTokenGrant($user->id(), $tenant, '019a0000-0000-7000-8000-0000000000aa', 'Claude', [ApiScope::Read, ApiScope::Write], new \DateTimeImmutable('2026-12-01T10:00:00+01:00'));

        $output = $this->tool($grant, $user, new Organisation($tenant, 'Tenant A', Role::Member))->call([]);

        self::assertSame([
            'user' => ['id' => $user->id(), 'email' => 'testing@example.com'],
            'tenant' => ['id' => $tenant->toString(), 'name' => 'Tenant A'],
            'token' => ['id' => '019a0000-0000-7000-8000-0000000000aa', 'name' => 'Claude', 'scopes' => ['read', 'write'], 'expires_at' => '2026-12-01T10:00:00+01:00'],
        ], $output->data);
        self::assertSame('The person, organisation and API token of this connection.', $output->summary);
    }

    public function testWithoutAReadingTokenItRefuses(): void
    {
        try {
            $this->tool(null)->call([]);
            self::fail('The tool answered without a token.');
        } catch (ToolRefusal $refused) {
            self::assertSame('insufficient_scope', $refused->errorCode);
            self::assertSame('The token does not have the "read" scope.', $refused->getMessage());
        }
    }

    private function tool(?ApiTokenGrant $grant, ?User $user = null, ?Organisation $organisation = null): WhoAmITool
    {
        $tokens = self::createStub(ApiTokenContext::class);
        if ($grant instanceof ApiTokenGrant) {
            $tokens->method('requireScope')->willReturn($grant);
        } else {
            $tokens->method('requireScope')->willThrowException(new InsufficientScope(ApiScope::Read));
        }
        $users = self::createStub(UserRepository::class);
        $users->method('findById')->willReturn($user);
        $memberships = self::createStub(MembershipDirectory::class);
        $memberships->method('organisationsOf')->willReturn($organisation instanceof Organisation ? [$organisation] : []);

        return new WhoAmITool(new CurrentApiIdentity($tokens, $users, $memberships));
    }
}
