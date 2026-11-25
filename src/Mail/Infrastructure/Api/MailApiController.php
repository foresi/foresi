<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Api;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\AddressReads;
use Foresi\Mail\Application\AddressView;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The person's own inbound addresses over REST, without their tokens. The
 * writes are the operations of Foresi\Mail\Application (ADR 0032).
 */
#[AsController]
final readonly class MailApiController
{
    public const string READ_SCOPE = 'mail_addresses:read';
    public const string ADDRESSES = 'api_v1_mail_addresses';

    public function __construct(private ScopeGuard $scope, private AddressReads $reads, private InstanceClock $clock)
    {
    }

    #[Route('/api/v1/mail/addresses', name: self::ADDRESSES, methods: ['GET'])]
    #[OA\Tag(name: 'Mail')]
    #[OA\Parameter(name: 'purpose', description: 'Only the addresses of this purpose.', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Your inbound addresses, oldest first, revoked ones too; never their tokens.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MailAddress'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the mail_addresses:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function addresses(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $zone = $this->clock->timezone();
        $purpose = $request->query->get('purpose');

        return new JsonResponse(['data' => array_map(static fn (AddressView $view): array => MailJson::address($view, $zone), $this->reads->mine(\is_string($purpose) ? $purpose : null))]);
    }
}
