<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Controller;

use App\Banking\Application\ReceiveInboundMail;
use App\Banking\Application\ReceiveOutcome;
use App\Banking\Domain\InboundChannel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The optional webhook for a relay that hands over raw mail, such as an e-mail
 * worker: the RFC 5322 message is the body, and the shared secret is the bearer
 * token. Without a secret the route does not exist. The optional header
 * X-Foresi-Recipient names the recipient the relay saw.
 *
 * Stored, duplicate and unknown recipient all answer 202, so the answer tells
 * nothing about which addresses exist. The secret is checked first, in constant
 * time. A failed check is counted per client address and answered 429 once the
 * budget is spent, which keeps guessing slow; it is never what stops the real
 * relay, which often shares its address with strangers, so a request with the
 * secret only counts against a budget of its own, per client address, high enough
 * for any bank. A secret of 256 random bits is not guessed anyway. Stateless: no
 * session, no user and no tenant of a request. Never log the secret or the
 * recipient.
 */
#[AsController]
final readonly class InboundMailController
{
    public const string ROUTE = 'app_inbound_mail';
    public const string RECIPIENT_HEADER = 'X-Foresi-Recipient';

    public function __construct(
        private ReceiveInboundMail $receive,
        #[Autowire('%env(string:INBOUND_WEBHOOK_SECRET)%')]
        private string $secret,
        #[Autowire(service: 'limiter.inbound_mail')]
        private RateLimiterFactoryInterface $failures,
        #[Autowire(service: 'limiter.inbound_mail_relay')]
        private RateLimiterFactoryInterface $relay,
    ) {
    }

    #[Route('/inbound/mail', name: self::ROUTE, methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ('' === $this->secret) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $client = $request->getClientIp() ?? 'unknown';
        if (!$this->authorised($request)) {
            return $this->failures->create($client)->consume()->isAccepted()
                ? new Response('', Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer'])
                : new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!$this->relay->create($client)->consume()->isAccepted()) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $length = $request->headers->get('Content-Length');
        if (null !== $length && is_numeric($length) && (int) $length > ReceiveInboundMail::MAX_BYTES) {
            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $recipient = $request->headers->get(self::RECIPIENT_HEADER);

        return match ($this->receive->receive($request->getContent(), InboundChannel::Webhook, null === $recipient || '' === trim($recipient) ? null : $recipient)) {
            ReceiveOutcome::Stored, ReceiveOutcome::Duplicate, ReceiveOutcome::UnknownRecipient => new Response('', Response::HTTP_ACCEPTED),
            ReceiveOutcome::TooLarge => new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE),
            ReceiveOutcome::Unreadable => new Response('', Response::HTTP_BAD_REQUEST),
        };
    }

    private function authorised(Request $request): bool
    {
        $header = $request->headers->get('Authorization') ?? '';
        if (1 !== preg_match('/^Bearer (\S+)$/D', $header, $match)) {
            return false;
        }

        return hash_equals($this->secret, $match[1]);
    }
}
