<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Controller;

use Foresi\Shared\Infrastructure\Security\PublicRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The setup of the MCP server for an AI agent, as Markdown with this installation's addresses
 * filled in. A person copies a one-line prompt from Settings > API and MCP > MCP ("Install the
 * Foresi MCP server by following <this file>") and the agent does the rest, as with the setup
 * guides of Context7 (context7.com/get-started.md) and Neon (neon.com/prompts/agent-setup.md).
 * /llms.txt points to it, after the llms.txt convention (llmstxt.org).
 *
 * Both are public and hold no data: only the addresses of this installation, which whoever got
 * the prompt already knows. Anyone may read them without signing in, as an agent has no session.
 */
final class McpSetupController extends AbstractController
{
    public const string ROUTE = 'app_mcp_setup';
    public const string LLMS_ROUTE = 'app_llms_txt';
    /** The MCP server over Streamable HTTP (config/packages/mcp.yaml). */
    private const string MCP_ROUTE = '_mcp_endpoint_default';
    /** An hour: the addresses change only with the domain of the installation. */
    private const int MAX_AGE = 3600;

    #[Route('/mcp-setup.md', name: self::ROUTE, defaults: [PublicRoute::DEFAULT => true], methods: ['GET'], stateless: true)]
    public function setup(): Response
    {
        return $this->text('@Identity/api/mcp-setup.md.twig', 'text/markdown; charset=UTF-8');
    }

    #[Route('/llms.txt', name: self::LLMS_ROUTE, defaults: [PublicRoute::DEFAULT => true], methods: ['GET'], stateless: true)]
    public function llms(): Response
    {
        return $this->text('@Identity/api/llms.txt.twig', 'text/plain; charset=UTF-8');
    }

    private function text(string $template, string $type): Response
    {
        $response = $this->render($template, [
            'server' => $this->generateUrl(self::MCP_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            'setup' => $this->generateUrl(self::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            'tokens' => $this->generateUrl(ApiTokenController::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            'home' => $this->generateUrl('app_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
        $response->headers->set('Content-Type', $type);
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);

        return $response;
    }
}
