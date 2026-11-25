<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\AiReader\Unit\Claude;

use Foresi\AiReader\Application\AiReaderSettings;
use Foresi\AiReader\Application\ExtractionSchema;
use Foresi\AiReader\Infrastructure\Claude\ClaudeProvider;
use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @phpstan-type Request array{method: string, url: string, headers: array<string, list<string>>, body: array{model: string, max_tokens: int, output_config: array<string, mixed>, messages: list<array{role: string, content: list<array<string, mixed>>}>}}
 */
final class ClaudeProviderTest extends TestCase
{
    private const string KEY = 'sk-test-not-a-real-key';
    private const string INSTRUCTIONS = 'Read the example invoice.';
    private const string PNG = "\x89PNG\r\n\x1a\n example";
    private const string JPEG = "\xFF\xD8\xFF example";

    public function testSendsPdfAsDocumentBlockWithJsonSchemaOutputConfig(): void
    {
        $requests = [];
        $provider = $this->provider($requests, $this->answer('{"total":"121.00"}'));

        $provider->extract(new DocumentFile('invoice.pdf', 'application/pdf', '%PDF-1.4 example'), ExtractionSchema::fields(), self::INSTRUCTIONS);

        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.anthropic.com/v1/messages', $request['url']);
        self::assertSame(self::KEY, $this->header($request, 'x-api-key'));
        self::assertSame('2026-10-21', $this->header($request, 'anthropic-version'));
        self::assertStringStartsWith('application/json', (string) $this->header($request, 'content-type'));

        $body = $request['body'];
        self::assertSame('claude-sonnet-5-5', $body['model']);
        self::assertSame(4096, $body['max_tokens']);
        self::assertSame(['format' => ['type' => 'json_schema', 'schema' => ExtractionSchema::fields()], 'effort' => 'low'], $body['output_config']);
        foreach (['tools', 'tool_choice', 'temperature', 'thinking'] as $absent) {
            self::assertArrayNotHasKey($absent, $body);
        }
        self::assertCount(1, $body['messages']);
        self::assertSame('user', $body['messages'][0]['role']);
        self::assertSame(
            [
                ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 example')]],
                ['type' => 'text', 'text' => self::INSTRUCTIONS],
            ],
            $body['messages'][0]['content'],
        );
    }

    public function testSendsPngAsImageBlock(): void
    {
        $requests = [];
        $provider = $this->provider($requests, $this->answer('{}'));

        $provider->extract(new DocumentFile('receipt.png', 'image/png', self::PNG), [], self::INSTRUCTIONS);

        self::assertSame(
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode(self::PNG)]],
            $requests[0]['body']['messages'][0]['content'][0],
        );
    }

    public function testSendsJpegAsImageBlock(): void
    {
        $requests = [];
        $provider = $this->provider($requests, $this->answer('{}'));

        $provider->extract(new DocumentFile('receipt.jpg', 'image/jpeg', self::JPEG), [], self::INSTRUCTIONS);

        self::assertSame('image/jpeg', ((array) $requests[0]['body']['messages'][0]['content'][0]['source'])['media_type']);
    }

    public function testAFileOfAnotherTypeIsNotSent(): void
    {
        $requests = [];
        $provider = $this->provider($requests, $this->answer('{}'));

        try {
            $provider->extract(new DocumentFile('note.gif', 'image/gif', 'GIF89a example'), [], self::INSTRUCTIONS);
            self::fail('A GIF is not sent.');
        } catch (ReaderFailed $e) {
            self::assertSame('unsupported_file', $e->getMessage());
        }
        self::assertSame([], $requests);
    }

    public function testReturnsTheJsonOfTheFirstTextBlock(): void
    {
        $requests = [];
        $provider = $this->provider($requests, $this->message([
            ['type' => 'thinking', 'thinking' => 'The total is 121.00.', 'signature' => 'example'],
            ['type' => 'text', 'text' => '{"total":"121.00","currency":"CZK"}'],
            ['type' => 'text', 'text' => '{"total":"1.00"}'],
        ]));

        $answer = $provider->extract($this->pdf(), [], self::INSTRUCTIONS);

        self::assertSame(['total' => '121.00', 'currency' => 'CZK'], $answer);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function httpErrors(): iterable
    {
        yield '401' => [401, 'provider_auth'];
        yield '403' => [403, 'provider_auth'];
        yield '413' => [413, 'too_large'];
        yield '429' => [429, 'provider_busy'];
        yield '529' => [529, 'provider_busy'];
        yield '500' => [500, 'provider_unavailable'];
        yield '503' => [503, 'provider_unavailable'];
        yield '400' => [400, 'provider_unavailable'];
    }

    #[DataProvider('httpErrors')]
    public function testMapsHttpErrorsToCodes(int $status, string $code): void
    {
        $requests = [];
        $provider = $this->provider($requests, new MockResponse('{"error":{"message":"secret '.self::KEY.' body"}}', ['http_code' => $status]));

        try {
            $provider->extract($this->pdf(), [], self::INSTRUCTIONS);
            self::fail('The error must be raised.');
        } catch (ReaderFailed $e) {
            self::assertSame($code, $e->getMessage());
        }
    }

    public function testATimeoutIsUnavailable(): void
    {
        $requests = [];
        $provider = $this->provider($requests, new MockResponse('', ['error' => 'Idle timeout reached for "https://api.anthropic.com/v1/messages".']));

        try {
            $provider->extract($this->pdf(), [], self::INSTRUCTIONS);
            self::fail('The error must be raised.');
        } catch (ReaderFailed $e) {
            self::assertSame('provider_unavailable', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testNoTextBlockIsInvalidAnswer(): void
    {
        $this->assertInvalid($this->message([['type' => 'thinking', 'thinking' => 'Hmm.', 'signature' => 'example']]));
        $this->assertInvalid($this->message([]));
        $this->assertInvalid(new MockResponse('{}'));
    }

    public function testAnAnswerThatIsNotAJsonObjectIsInvalid(): void
    {
        $this->assertInvalid($this->answer('not json'));
        $this->assertInvalid($this->answer('"text"'));
        $this->assertInvalid($this->answer('12'));
        $this->assertInvalid(new MockResponse('<html>gateway</html>'));
    }

    public function testRefusalAndMaxTokensAreInvalidAnswer(): void
    {
        $this->assertInvalid($this->message([['type' => 'text', 'text' => '{"total":"1.00"}']], 'refusal'));
        $this->assertInvalid($this->message([['type' => 'text', 'text' => '{"total":"1.00"']], 'max_tokens'));
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            return new MockResponse('{}');
        });
        $provider = new ClaudeProvider(new AiReaderSettings('claude', '', '', ''), $client);

        try {
            $provider->extract($this->pdf(), [], self::INSTRUCTIONS);
            self::fail('An unconfigured provider cannot answer.');
        } catch (ReaderFailed $e) {
            self::assertSame('not_configured', $e->getMessage());
        }
        self::assertSame([], $requests);
    }

    public function testKeyIsNotInAnyExceptionOrLog(): void
    {
        $log = [];
        $file = (string) tempnam(sys_get_temp_dir(), 'claude-provider');
        $previous = ini_set('error_log', $file);
        try {
            $responses = [
                new MockResponse('{"error":"bad key '.self::KEY.'"}', ['http_code' => 401]),
                new MockResponse('{"error":"busy"}', ['http_code' => 529]),
                new MockResponse('', ['error' => 'Connection failed for sk-test-not-a-real-key']),
                $this->answer('nope '.self::KEY),
            ];
            foreach ($responses as $response) {
                $requests = [];
                try {
                    $this->provider($requests, $response)->extract($this->pdf(), [], self::INSTRUCTIONS);
                    self::fail('The error must be raised.');
                } catch (ReaderFailed $e) {
                    $log[] = $e->getMessage()."\n".$e->getTraceAsString()."\n".print_r($e->getPrevious(), true);
                }
            }
        } finally {
            ini_set('error_log', false === $previous ? '' : $previous);
            $log[] = (string) file_get_contents($file);
            unlink($file);
        }

        self::assertStringNotContainsString(self::KEY, implode("\n", $log));
        self::assertStringNotContainsString(base64_encode('%PDF-1.4 example'), implode("\n", $log));
    }

    /**
     * @param list<Request> $requests filled with the requests made
     */
    private function provider(array &$requests, MockResponse $response): ClaudeProvider
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $response): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['normalized_headers'] ?? [],
                'body' => json_decode(\is_string($options['body']) ? $options['body'] : '', true, flags: \JSON_THROW_ON_ERROR),
            ];

            return $response;
        });

        return new ClaudeProvider(new AiReaderSettings('claude', self::KEY, '', ''), $client);
    }

    /**
     * @param Request $request
     */
    private function header(array $request, string $name): ?string
    {
        $values = $request['headers'][$name] ?? [];

        return isset($values[0]) ? trim(substr($values[0], \strlen($name) + 1)) : null;
    }

    private function assertInvalid(MockResponse $response): void
    {
        $requests = [];
        try {
            $this->provider($requests, $response)->extract($this->pdf(), [], self::INSTRUCTIONS);
            self::fail('The answer must be refused.');
        } catch (ReaderFailed $e) {
            self::assertSame('invalid_answer', $e->getMessage());
        }
    }

    private function answer(string $text): MockResponse
    {
        return $this->message([['type' => 'text', 'text' => $text]]);
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function message(array $content, string $stopReason = 'end_turn'): MockResponse
    {
        return new MockResponse(json_encode(['id' => 'msg_example', 'type' => 'message', 'role' => 'assistant', 'content' => $content, 'stop_reason' => $stopReason], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function pdf(): DocumentFile
    {
        return new DocumentFile('invoice.pdf', 'application/pdf', '%PDF-1.4 example');
    }
}
