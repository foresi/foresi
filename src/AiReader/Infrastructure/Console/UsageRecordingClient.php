<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Infrastructure\Console;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Hands the requests to the client it wraps and keeps the last response, so the tokens the provider reports in
 * `usage` can be read after the provider is done, without a change to the AiProvider port. It reads nothing
 * but those two numbers: never the request, the headers or the answer.
 */
final class UsageRecordingClient implements HttpClientInterface
{
    private ?ResponseInterface $last = null;

    public function __construct(private readonly HttpClientInterface $inner)
    {
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->last = $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }

    /**
     * @return array{input_tokens: int|null, output_tokens: int|null} null for what the last response did not report
     */
    public function usage(): array
    {
        $none = ['input_tokens' => null, 'output_tokens' => null];
        try {
            if (!$this->last instanceof ResponseInterface || 200 !== $this->last->getStatusCode()) {
                return $none;
            }
            $message = json_decode($this->last->getContent(false), true);
        } catch (\Throwable) {
            return $none;
        }
        $usage = \is_array($message) && \is_array($message['usage'] ?? null) ? $message['usage'] : [];

        return [
            'input_tokens' => \is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            'output_tokens' => \is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
        ];
    }
}
