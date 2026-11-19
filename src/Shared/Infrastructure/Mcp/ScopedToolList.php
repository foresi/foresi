<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Mcp;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Api\ScopeGuard;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Answers tools/list with the tools the token's scopes allow, as the MCP spec
 * lets a server do (ADR 0032): an outreach token with contacts:write sees the
 * contact tools and nothing of invoices or webhooks. It takes the place of the
 * SDK's own handler, which the server asks after ours. A guest's token sees
 * no tool that is for the organisation's own people only (ADR 0041). The
 * list is one page: Foresi has a few dozen tools. Calling a tool that is not
 * listed is still refused by the checks of the call.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final readonly class ScopedToolList implements RequestHandlerInterface
{
    public function __construct(
        #[Autowire(service: 'mcp.server.default.registry')]
        private RegistryInterface $registry,
        private ReadToolLoader $tools,
        private ScopeGuard $scopes,
        private ActorRole $role,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListToolsRequest);
        $scopes = $this->tools->scopes();
        $hidden = $this->role->isStaff() ? [] : $this->tools->staffOnly();
        $tools = array_values(array_filter(
            $this->registry->getTools()->references,
            fn (Tool $tool): bool => isset($scopes[$tool->name]) && !isset($hidden[$tool->name]) && $this->scopes->allows($scopes[$tool->name]),
        ));

        return new Response($request->getId(), new ListToolsResult($tools));
    }
}
