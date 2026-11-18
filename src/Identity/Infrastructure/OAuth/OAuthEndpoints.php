<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\OAuth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The addresses of the OAuth authorization server and of the MCP server it
 * protects (ADR 0036), as seen from the request: the issuer is the origin of
 * the installation, the resource the absolute URL of /mcp.
 */
final readonly class OAuthEndpoints
{
    /** The MCP server over Streamable HTTP (config/packages/mcp.yaml). */
    public const string MCP_ROUTE = '_mcp_endpoint_default';
    public const string RESOURCE_METADATA_PATH = '/.well-known/oauth-protected-resource';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function issuer(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    public function resource(): string
    {
        return $this->urls->generate(self::MCP_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Where the metadata of the MCP server is (RFC 9728, 3.1): the well-known
     * path with the path of the resource after it.
     */
    public static function resourceMetadataUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost().self::RESOURCE_METADATA_PATH.'/mcp';
    }

    /**
     * A JSON answer any web page may read: these endpoints take no cookies.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_UNESCAPED_SLASHES);
        self::cors($response);

        return $response;
    }

    /**
     * An OAuth error (RFC 6749, 5.2), never cached.
     */
    public static function error(string $error, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        $response = self::json(['error' => $error, 'error_description' => $description], $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public static function preflight(): Response
    {
        $response = new Response(null, Response::HTTP_NO_CONTENT);
        self::cors($response);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, MCP-Protocol-Version');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    private static function cors(Response $response): void
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
    }
}
