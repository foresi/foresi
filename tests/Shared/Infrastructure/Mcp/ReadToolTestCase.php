<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;
use App\Shared\Infrastructure\Mcp\WriteTool;
use App\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A read or write tool of a module, called as the MCP server calls it once the token is
 * signed in: as the member, in the organisation. What it answers is compared
 * with what the REST API answers for the same data.
 */
abstract class ReadToolTestCase extends ApiTestCase
{
    /**
     * The tool as the MCP server has it.
     *
     * @template T of ReadTool|WriteTool
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function tool(string $class): ReadTool|WriteTool
    {
        $tool = self::getContainer()->get($class);
        self::assertInstanceOf($class, $tool);

        return $tool;
    }

    /**
     * The schema of one argument of the tool.
     *
     * @return array<mixed>
     */
    protected static function argument(ReadTool|WriteTool $tool, string $name): array
    {
        $properties = $tool->inputSchema()['properties'] ?? null;
        self::assertIsArray($properties);
        $argument = $properties[$name] ?? null;
        self::assertIsArray($argument);

        return $argument;
    }

    /**
     * @return list<string> the names of the arguments of the tool, in the order of its schema
     */
    protected static function argumentNames(ReadTool|WriteTool $tool): array
    {
        $properties = $tool->inputSchema()['properties'] ?? null;
        self::assertIsArray($properties);

        return array_map(strval(...), array_keys($properties));
    }

    /**
     * @param class-string<ReadTool|WriteTool> $tool
     * @param array<string, mixed>             $arguments
     */
    protected function call(string $tool, array $arguments = [], ?User $as = null): ToolOutput
    {
        $instance = $this->tool($tool);
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $tokens->setToken(new UsernamePasswordToken(SecurityUser::fromUser($as ?? $this->user), 'mcp', ['ROLE_USER']));
        $this->context()->switchTo($this->tenant);
        try {
            return $instance->call($arguments);
        } finally {
            $this->context()->reset();
            $tokens->setToken(null);
        }
    }

    /**
     * @param class-string<ReadTool|WriteTool> $tool
     * @param array<string, mixed>             $arguments
     */
    protected function refusal(string $tool, array $arguments = [], ?User $as = null): ToolRefusal
    {
        try {
            $this->call($tool, $arguments, $as);
        } catch (ToolRefusal $refusal) {
            return $refusal;
        }
        self::fail('The tool answered.');
    }

    /**
     * @param class-string<ReadTool|WriteTool> $tool
     * @param array<string, mixed>             $arguments
     */
    protected function assertRefused(string $code, string $message, string $tool, array $arguments = [], ?User $as = null): void
    {
        $refusal = $this->refusal($tool, $arguments, $as);
        self::assertSame($code, $refusal->errorCode);
        self::assertSame($message, $refusal->getMessage());
    }

    /**
     * A guest of the organisation, who reads none of its records.
     */
    protected function guest(): User
    {
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, $this->tenant, Role::Guest);

        return $guest;
    }

    /**
     * The data the REST API answers for the path, read with the member's token.
     *
     * @param array<string, string> $query
     */
    protected function rest(string $path, array $query = []): mixed
    {
        $this->get($path, $query);
        self::assertResponseIsSuccessful();

        return $this->json()['data'];
    }

    /**
     * @return list<string> the ids of the items of a search
     */
    protected static function itemIds(ToolOutput $output): array
    {
        $items = $output->data['items'];
        self::assertIsArray($items);

        return array_values(array_map(static fn (mixed $item): string => \is_array($item) && \is_string($item['id'] ?? null) ? $item['id'] : throw new \LogicException('An item has no id.'), $items));
    }

    /**
     * Pages through a search with the given limit, and answers the ids of each page.
     *
     * @param class-string<ReadTool|WriteTool> $tool
     * @param array<string, mixed>             $arguments
     *
     * @return list<list<string>>
     */
    protected function toolPages(string $tool, int $limit, array $arguments = []): array
    {
        $pages = [];
        $cursor = null;
        do {
            $output = $this->call($tool, $arguments + ['limit' => $limit] + (null === $cursor ? [] : ['cursor' => $cursor]));
            $pages[] = self::itemIds($output);
            $cursor = $output->data['next_cursor'];
            self::assertTrue(null === $cursor || \is_string($cursor));
        } while (null !== $cursor && \count($pages) < 10);

        return $pages;
    }
}
