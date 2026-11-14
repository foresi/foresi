<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Api;

use App\Shared\Application\Api\IdempotencyKeyInFlight;
use App\Shared\Application\Api\IdempotencyKeyReused;
use App\Shared\Application\Api\SavedAnswer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The Idempotency-Key of a REST write (POST, PATCH or DELETE under /api/v1),
 * for every write, the hand-written ones too (ADR 0032). The key is claimed
 * after the token signed in and the rate limit let the request through, and
 * before the controller runs. A repeat with the same input gets the saved
 * answer with Idempotent-Replayed: true; with another input 422
 * idempotency_key_reused; while the first still runs 409
 * idempotency_key_in_flight. The answer is kept or the key let go when the
 * response leaves. Without the header nothing changes.
 */
final readonly class ApiIdempotency
{
    private const string ATTRIBUTE = '_api_idempotency';
    private const array WRITES = ['POST', 'PATCH', 'DELETE'];
    private const string PREFIX = '/api/v1/';

    public function __construct(private Idempotency $idempotency)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 2)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->headers->has(Idempotency::HEADER) || !$this->isWrite($request)) {
            return;
        }
        $key = (string) $request->headers->get(Idempotency::HEADER);
        if (!Idempotency::validKey($key)) {
            $event->setResponse(ProblemResponse::create(Response::HTTP_BAD_REQUEST, ApiErrors::INVALID_PARAMETER, Idempotency::INVALID, extensions: ['parameter' => Idempotency::HEADER]));

            return;
        }
        $operation = 'rest:'.$request->attributes->getString('_route');
        try {
            $saved = $this->idempotency->claim($operation, $key, Idempotency::fingerprint([$request->getMethod(), $request->getPathInfo(), $this->body($request)]));
        } catch (IdempotencyKeyReused $reused) {
            $event->setResponse(ProblemResponse::create(Response::HTTP_UNPROCESSABLE_ENTITY, IdempotencyKeyReused::CODE, $reused->getMessage()));

            return;
        } catch (IdempotencyKeyInFlight $inFlight) {
            $event->setResponse(ProblemResponse::create(Response::HTTP_CONFLICT, IdempotencyKeyInFlight::CODE, $inFlight->getMessage(), ['Retry-After' => '1']));

            return;
        }
        if ($saved instanceof SavedAnswer) {
            $response = new Response($saved->body, $saved->status ?? Response::HTTP_OK, $saved->headers);
            $response->headers->set(Idempotency::REPLAYED_HEADER, 'true');
            $event->setResponse($response);

            return;
        }
        $request->attributes->set(self::ATTRIBUTE, [$operation, $key]);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -8)]
    public function onResponse(ResponseEvent $event): void
    {
        $claim = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!\is_array($claim) || !\is_string($claim[0] ?? null) || !\is_string($claim[1] ?? null)) {
            return;
        }
        $event->getRequest()->attributes->remove(self::ATTRIBUTE);
        [$operation, $key] = $claim;
        $response = $event->getResponse();
        $headers = array_filter([
            'Content-Type' => $response->headers->get('Content-Type'),
            'Location' => $response->headers->get('Location'),
        ], \is_string(...));
        $this->idempotency->finish($operation, $key, new SavedAnswer($response->getStatusCode(), (string) $response->getContent(), $headers), $this->code($response));
    }

    private function isWrite(Request $request): bool
    {
        return \in_array($request->getMethod(), self::WRITES, true) && str_starts_with($request->getPathInfo(), self::PREFIX);
    }

    private function body(Request $request): mixed
    {
        $content = $request->getContent();
        if ('' === $content) {
            return null;
        }
        try {
            return json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $content;
        }
    }

    /**
     * The stable code of a problem document, if the answer is one.
     */
    private function code(Response $response): ?string
    {
        if (!str_starts_with((string) $response->headers->get('Content-Type'), ProblemResponse::CONTENT_TYPE)) {
            return null;
        }
        $problem = json_decode((string) $response->getContent(), true);

        return \is_array($problem) && \is_string($problem['code'] ?? null) ? $problem['code'] : null;
    }
}
