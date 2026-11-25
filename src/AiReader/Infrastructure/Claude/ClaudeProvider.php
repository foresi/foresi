<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Infrastructure\Claude;

use Foresi\AiReader\Application\AiProvider;
use Foresi\AiReader\Application\AiReaderSettings;
use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the Claude Messages API about one file and returns the JSON it answers.
 *
 * The answer is constrained by a JSON Schema in `output_config`; no tool is
 * offered, so the only thing that comes back is the text of the model. The
 * file and the instructions travel in one user message, the file first. The
 * document is data: nothing it says can reach a tool, because there is none.
 *
 * A failure is a short code and nothing else: the exception never carries the
 * response body, the request or the key, and nothing is logged here (the caller logs the code).
 */
final readonly class ClaudeProvider implements AiProvider
{
    public const string ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public const string API_VERSION = '2026-10-21';
    public const int MAX_TOKENS = 4096;
    public const int TIMEOUT_SECONDS = 60;

    public function __construct(private AiReaderSettings $settings, private HttpClientInterface $http)
    {
    }

    public function extract(DocumentFile $file, array $schema, string $instructions): array
    {
        if (!$this->settings->isConfigured()) {
            throw new ReaderFailed('not_configured');
        }
        $body = [
            'model' => $this->settings->model(),
            'max_tokens' => self::MAX_TOKENS,
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
                'effort' => 'low',
            ],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    $this->fileBlock($file),
                    ['type' => 'text', 'text' => $instructions],
                ],
            ]],
        ];

        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'headers' => [
                    'x-api-key' => $this->settings->apiKey(),
                    'anthropic-version' => self::API_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $content = 200 === $status ? $response->getContent() : '';
        } catch (ExceptionInterface) {
            // The exception text may quote the request; only the code leaves this class.
            throw new ReaderFailed('provider_unavailable');
        }

        if (200 !== $status) {
            throw new ReaderFailed($this->failureOf($status));
        }

        return $this->answer($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileBlock(DocumentFile $file): array
    {
        if ($file->isPdf()) {
            $type = 'document';
            $mediaType = 'application/pdf';
        } elseif (str_starts_with($file->bytes, "\x89PNG\r\n\x1a\n")) {
            $type = 'image';
            $mediaType = 'image/png';
        } elseif (str_starts_with($file->bytes, "\xFF\xD8\xFF")) {
            $type = 'image';
            $mediaType = 'image/jpeg';
        } else {
            throw new ReaderFailed('unsupported_file');
        }

        return ['type' => $type, 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => base64_encode($file->bytes)]];
    }

    private function failureOf(int $status): string
    {
        return match (true) {
            401 === $status, 403 === $status => 'provider_auth',
            400 === $status, 404 === $status => 'provider_rejected',
            413 === $status => 'too_large',
            429 === $status, 529 === $status => 'provider_busy',
            default => 'provider_unavailable',
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function answer(string $content): array
    {
        $message = json_decode($content, true);
        if (!\is_array($message) || \in_array($message['stop_reason'] ?? null, ['refusal', 'max_tokens'], true)) {
            throw new ReaderFailed('invalid_answer');
        }
        $blocks = $message['content'] ?? null;
        if (!\is_array($blocks)) {
            throw new ReaderFailed('invalid_answer');
        }
        foreach ($blocks as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null)) {
                $answer = \is_string($block['text'] ?? null) ? json_decode($block['text'], true) : null;
                if (!\is_array($answer)) {
                    throw new ReaderFailed('invalid_answer');
                }

                return $answer;
            }
        }

        throw new ReaderFailed('invalid_answer');
    }
}
