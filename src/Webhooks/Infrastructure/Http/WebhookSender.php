<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Http;

use App\Webhooks\Application\AttemptResult;
use App\Webhooks\Domain\WebhookSecret;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts one signed body to a receiver and reports the status. The body goes out
 * as the string that was signed, never re-encoded. Only the status is read:
 * what a receiver answers with is not ours to keep, and the response is
 * cancelled unread. The client does not follow redirects and refuses private
 * addresses (webhooks.http_client), so a 3xx is a failed attempt.
 */
final readonly class WebhookSender
{
    public function __construct(
        #[Autowire(service: 'webhooks.http_client')]
        private HttpClientInterface $client,
        private StandardWebhookSigner $signer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<WebhookSecret> $secrets
     */
    public function send(string $url, string $id, string $body, array $secrets): AttemptResult
    {
        $headers = $this->signer->headers($id, $this->clock->now()->getTimestamp(), $body, $secrets);
        $started = hrtime(true);
        $response = null;

        try {
            $response = $this->client->request('POST', $url, ['body' => $body, 'headers' => $headers]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            return new AttemptResult(null, $this->elapsed($started), $this->errorOf($e));
        } finally {
            $response?->cancel();
        }

        return new AttemptResult($status, $this->elapsed($started), $status >= 300 && $status < 400 ? AttemptResult::REDIRECT : null);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function errorOf(TransportExceptionInterface $e): string
    {
        if ($e instanceof TimeoutExceptionInterface) {
            return AttemptResult::TIMEOUT;
        }

        // Symfony has no typed exceptions for these, so they are told apart by message: the
        // client's max_duration abort ("Max duration was reached for ..."), the refusal of
        // NoPrivateNetworkHttpClient ("... is blocked for ...") and TLS failures.
        $message = $e->getMessage();
        if (str_starts_with($message, 'Max duration was reached')) {
            return AttemptResult::TIMEOUT;
        }
        if (str_contains($message, ' is blocked for ')) {
            return AttemptResult::BLOCKED_ADDRESS;
        }
        if (1 === preg_match('/\b(SSL|TLS|certificate)\b/i', $message)) {
            return AttemptResult::TLS;
        }

        return AttemptResult::CONNECTION;
    }
}
