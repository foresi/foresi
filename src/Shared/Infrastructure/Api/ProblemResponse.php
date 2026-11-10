<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * An RFC 9457 problem document, as every API answer that refuses is written:
 * the status, its title, a sentence for people and a stable code for programs.
 * The type is about:blank, so the title is the status text.
 */
final class ProblemResponse
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /**
     * @param array<string, string>                   $headers
     * @param array<string, scalar|array<mixed>|null> $extensions more members, such as the parameter that is wrong
     */
    public static function create(int $status, string $code, string $detail, array $headers = [], array $extensions = []): JsonResponse
    {
        $response = new JsonResponse([
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
        ] + $extensions, $status, $headers);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}
