<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Http;

use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Http\StandardWebhookSigner;
use App\Webhooks\Infrastructure\Http\WebhookSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(WebhookSender::class)]
final class WebhookSenderTest extends TestCase
{
    private const string BODY = '{"id":"evt_1","type":"invoicing.invoice.issued","data":{"note":"a/b é"}}';

    private function sender(HttpClientInterface $client): WebhookSender
    {
        return new WebhookSender($client, new StandardWebhookSigner(), new MockClock('@1614265330'));
    }

    public function testSignatureVerifiesAgainstTheRawBodySent(): void
    {
        $secret = WebhookSecret::generate();
        $captured = ['method' => '', 'url' => '', 'body' => '', 'headers' => []];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $body = $options['body'] ?? '';
            \assert(\is_string($body));
            $headers = $options['headers'] ?? [];
            \assert(\is_array($headers));
            $captured = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

            return new MockResponse('', ['http_code' => 200]);
        });

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [$secret]);

        self::assertTrue($result->succeeded);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://example.com/hook', $captured['url']);
        self::assertSame(self::BODY, $captured['body']);

        $headers = [];
        foreach ($captured['headers'] as $line) {
            \assert(\is_string($line));
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }
        self::assertSame('evt_1', $headers['webhook-id']);
        self::assertSame('1614265330', $headers['webhook-timestamp']);
        $expected = 'v1,'.base64_encode(hash_hmac('sha256', 'evt_1.1614265330.'.self::BODY, $secret->key(), true));
        self::assertSame($expected, $headers['webhook-signature']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function successCodes(): iterable
    {
        yield '200' => [200];
        yield '204' => [204];
    }

    #[DataProvider('successCodes')]
    public function test2xxSucceeds(int $status): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => $status]));

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertTrue($result->succeeded);
        self::assertSame($status, $result->statusCode);
        self::assertNull($result->error);
        self::assertFalse($result->gone);
        self::assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function test302IsAFailureAndNotFollowed(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://127.0.0.1/']]);
        });

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertSame(1, $requests);
        self::assertFalse($result->succeeded);
        self::assertSame(302, $result->statusCode);
        self::assertSame('redirect', $result->error);
    }

    public function test410IsGone(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 410]));

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertFalse($result->succeeded);
        self::assertTrue($result->gone);
        self::assertSame(410, $result->statusCode);
    }

    public function test500Fails(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertFalse($result->succeeded);
        self::assertFalse($result->gone);
        self::assertSame(500, $result->statusCode);
        self::assertNull($result->error);
    }

    public function testTimeoutIsReportedAsTimeout(): void
    {
        $client = new MockHttpClient(static fn () => throw new TimeoutException('Idle timeout reached.'));

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertFalse($result->succeeded);
        self::assertNull($result->statusCode);
        self::assertSame('timeout', $result->error);
    }

    public function testMaxDurationAbortIsReportedAsTimeout(): void
    {
        $client = new MockHttpClient(static fn () => throw new TransportException('Max duration was reached for "https://example.com/hook".'));

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertSame('timeout', $result->error);
        self::assertNull($result->statusCode);
    }

    public function testTlsFailureIsReportedAsTls(): void
    {
        $client = new MockHttpClient(static fn () => throw new TransportException('SSL certificate problem: certificate has expired'));

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertSame('tls', $result->error);
    }

    public function testOtherTransportErrorsAreConnection(): void
    {
        $client = new MockHttpClient(static fn () => throw new TransportException('Could not resolve host'));

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertSame('connection', $result->error);
        self::assertNull($result->statusCode);
    }

    public function testPrivateAddressIsBlocked(): void
    {
        $requests = 0;
        $inner = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => 200]);
        });

        $result = $this->sender(new NoPrivateNetworkHttpClient($inner))->send('https://127.0.0.1/', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertSame(0, $requests);
        self::assertFalse($result->succeeded);
        self::assertSame('blocked_address', $result->error);
    }

    public function testBodyIsNeverRead(): void
    {
        $body = (static function (): \Generator {
            yield 'partial';
            throw new TransportException('the body stream must not be read');
        })();
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => 200]));

        $result = $this->sender($client)->send('https://example.com/hook', 'evt_1', self::BODY, [WebhookSecret::generate()]);

        self::assertTrue($result->succeeded);
        self::assertSame(200, $result->statusCode);
        self::assertNull($result->error);
    }
}
