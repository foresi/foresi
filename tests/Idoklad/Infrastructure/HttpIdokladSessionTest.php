<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Infrastructure;

use App\Idoklad\Application\IdokladAccessDenied;
use App\Idoklad\Application\IdokladFailure;
use App\Idoklad\Application\IdokladTariffWithoutApi;
use App\Idoklad\Domain\IdokladPathRefused;
use App\Idoklad\Infrastructure\Http\HttpIdokladClient;
use App\Idoklad\Infrastructure\Http\HttpIdokladSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpIdokladSessionTest extends TestCase
{
    private MockClock $clock;

    /** @var list<array{method: string, url: string, options: array<mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-06 10:00:00');
    }

    public function testATokenOfTheClientCredentialsFlowAuthorisesTheRead(): void
    {
        $session = $this->session([$this->token('first'), $this->answer(['Id' => 7])]);

        self::assertSame(['Id' => 7], $session->get('/Contacts/7'));

        self::assertSame('POST', $this->sent[0]['method']);
        self::assertSame('https://identity.idoklad.cz/server/connect/token', $this->sent[0]['url']);
        self::assertSame('grant_type=client_credentials&client_id=example-client&client_secret=example-secret&scope=idoklad_api', $this->sent[0]['options']['body']);
        self::assertSame('GET', $this->sent[1]['method']);
        self::assertSame('https://api.idoklad.cz/v3/Contacts/7', $this->sent[1]['url']);
        self::assertContains('Authorization: Bearer first', $this->headers(1));
        self::assertContains('Accept: application/json', $this->headers(1));
        self::assertSame([30.0, 30.0], [$this->sent[0]['options']['timeout'], $this->sent[1]['options']['timeout']]);
        self::assertSame(1, $session->requests(), 'The token request is not an API request.');
    }

    public function testTheTokenIsReusedUntilAMinuteBeforeItExpires(): void
    {
        $session = $this->session([$this->token('first', 3600), $this->answer(1), $this->answer(2), $this->token('second'), $this->answer(3)]);

        $session->get('/Contacts/1');
        $this->clock->sleep(3539);
        $session->get('/Contacts/2');
        $this->clock->sleep(1);
        $session->get('/Contacts/3');

        self::assertContains('Authorization: Bearer first', $this->headers(2));
        self::assertSame('POST', $this->sent[3]['method']);
        self::assertContains('Authorization: Bearer second', $this->headers(4));
    }

    public function testAnUnauthorisedAnswerFetchesANewTokenOnce(): void
    {
        $session = $this->session([$this->token('stale'), new MockResponse('', ['http_code' => 401]), $this->token('fresh'), $this->answer('ok')]);

        self::assertSame('ok', $session->get('/Contacts'));
        self::assertContains('Authorization: Bearer fresh', $this->headers(3));
        self::assertSame(2, $session->requests());
    }

    public function testASecondUnauthorisedAnswerIsAccessDenied(): void
    {
        $session = $this->session([$this->token('a'), new MockResponse('', ['http_code' => 401]), $this->token('b'), new MockResponse('', ['http_code' => 401])]);

        try {
            $session->get('/Contacts');
            self::fail('A token refused twice is not tried again.');
        } catch (IdokladAccessDenied $denied) {
            self::assertSame('iDoklad refused the access token of these credentials.', $denied->getMessage());
        }
        self::assertCount(4, $this->sent);
        self::assertSame(2, $session->requests());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusedLogins(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorised' => [401];
    }

    #[DataProvider('refusedLogins')]
    public function testRefusedCredentialsAreAccessDenied(int $status): void
    {
        $session = $this->session([new JsonMockResponse(['error' => 'invalid_client'], ['http_code' => $status])]);

        $this->expectException(IdokladAccessDenied::class);
        $this->expectExceptionMessageIsOrContains('iDoklad refused the client id or the client secret.');
        $session->get('/Contacts');
    }

    /**
     * @return iterable<string, array{ResponseInterface}>
     */
    public static function brokenTokens(): iterable
    {
        yield 'server error' => [new JsonMockResponse(['access_token' => 'x', 'expires_in' => 3600], ['http_code' => 500])];
        yield 'no token' => [new JsonMockResponse(['expires_in' => 3600])];
        yield 'an empty token' => [new JsonMockResponse(['access_token' => '', 'expires_in' => 3600])];
        yield 'no lifetime' => [new JsonMockResponse(['access_token' => 'x'])];
    }

    #[DataProvider('brokenTokens')]
    public function testAnAnswerWithoutAUsableTokenFails(ResponseInterface $answer): void
    {
        $session = $this->session([$answer]);

        $this->expectException(IdokladFailure::class);
        $this->expectExceptionMessageIsOrContains('iDoklad gave no access token.');
        $session->get('/Contacts');
    }

    public function testAnUnreachableIdentityServerFails(): void
    {
        $session = $this->session([new MockResponse('', ['error' => 'host unreachable'])]);

        $this->expectException(IdokladFailure::class);
        $this->expectExceptionMessageIsOrContains('iDoklad cannot be reached.');
        $session->get('/Contacts');
    }

    public function testATariffWithoutApiIsItsOwnError(): void
    {
        $session = $this->session([$this->token('t'), new JsonMockResponse(['IsSuccess' => false], ['http_code' => 402])]);

        $this->expectException(IdokladTariffWithoutApi::class);
        $this->expectExceptionMessageIsOrContains('The iDoklad tariff of this account has no API access, or it expired.');
        $session->get('/Contacts');
    }

    public function testTooManyRequestsWaitsAsRetryAfterSays(): void
    {
        $session = $this->session([
            $this->token('t'),
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '7']]),
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '600']]),
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]),
            $this->answer('ok'),
        ]);

        self::assertSame('ok', $session->get('/Contacts'));
        self::assertSame('2026-11-06 10:01:08', $this->clock->now()->format('Y-m-d H:i:s'), '7 seconds, capped at 60, at least 1.');
        self::assertSame(4, $session->requests());
    }

    public function testTooManyRequestsWithoutRetryAfterBacksOffAndGivesUp(): void
    {
        $session = $this->session([$this->token('t'), ...array_fill(0, 5, new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => 'Fri, 06 Nov 2026 10:00:00 GMT']]))]);

        try {
            $session->get('/Contacts');
            self::fail('Five refusals in a row stop the pull.');
        } catch (IdokladFailure $failure) {
            self::assertSame('iDoklad refuses more requests for now; try again in a few minutes.', $failure->getMessage());
        }
        self::assertSame('2026-11-06 10:01:15', $this->clock->now()->format('Y-m-d H:i:s'), '5 + 10 + 20 + 40 seconds.');
        self::assertSame(5, $session->requests());
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function failures(): iterable
    {
        yield 'not found' => [new MockResponse('{}', ['http_code' => 404]), 'iDoklad answered with status 404.'];
        yield 'server error' => [new MockResponse('{}', ['http_code' => 500]), 'iDoklad answered with status 500.'];
        yield 'not JSON' => [new MockResponse('<html></html>'), 'iDoklad gave an answer that is not JSON.'];
        yield 'no success' => [new MockResponse('{"IsSuccess": false, "Data": 1}'), 'iDoklad did not succeed with this request.'];
        yield 'success as text' => [new MockResponse('{"IsSuccess": "true", "Data": 1}'), 'iDoklad did not succeed with this request.'];
        yield 'unreachable' => [new MockResponse('', ['error' => 'timeout']), 'iDoklad cannot be reached.'];
    }

    #[DataProvider('failures')]
    public function testOtherAnswersFail(MockResponse $answer, string $message): void
    {
        $session = $this->session([$this->token('t'), $answer]);

        $this->expectException(IdokladFailure::class);
        $this->expectExceptionMessageIsOrContains($message);
        $session->get('/Contacts');
    }

    public function testARefusedPathSendsNothing(): void
    {
        $session = $this->session([]);

        foreach (['/Batch/Exported', '/Mails/IssuedInvoice/Send'] as $path) {
            try {
                $session->get($path);
                self::fail($path.' is refused.');
            } catch (IdokladPathRefused) {
            }
        }
        self::assertSame([], $this->sent);
        self::assertSame(0, $session->requests());
    }

    public function testEveryPageIsReadWith200RecordsAPage(): void
    {
        $session = $this->session([
            $this->token('t'),
            $this->page([['Id' => 1], ['Id' => 2]], 3),
            $this->page([['Id' => 3]], 3),
            $this->page([['Id' => 4]], 3),
        ]);

        $pages = iterator_to_array($session->pages('/IssuedInvoices', ['filter' => '(DateLastChange~gt~2026-10-01T00:00:00)']));

        self::assertSame([1 => [['Id' => 1], ['Id' => 2]], 2 => [['Id' => 3]], 3 => [['Id' => 4]]], $pages);
        self::assertSame('https://api.idoklad.cz/v3/IssuedInvoices?page=1&pagesize=200&filter=(DateLastChange~gt~2026-10-01T00:00:00)', $this->sent[1]['url']);
        self::assertSame('https://api.idoklad.cz/v3/IssuedInvoices?page=3&pagesize=200&filter=(DateLastChange~gt~2026-10-01T00:00:00)', $this->sent[3]['url']);
        self::assertSame(3, $session->requests());
    }

    public function testAnEmptyListIsOnePage(): void
    {
        $session = $this->session([$this->token('t'), $this->page([], 0)]);

        self::assertSame([1 => []], iterator_to_array($session->pages('/Contacts')));
        self::assertCount(2, $this->sent);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badLists(): iterable
    {
        yield 'no list' => ['text'];
        yield 'no items' => [['TotalPages' => 1]];
        yield 'items not a list' => [['Items' => ['a' => ['Id' => 1]], 'TotalPages' => 1]];
        yield 'an item not an object' => [['Items' => [['Id' => 1], 2], 'TotalPages' => 1]];
        yield 'no page count' => [['Items' => [], 'TotalPages' => '1']];
    }

    #[DataProvider('badLists')]
    public function testAListOfAnotherShapeFails(mixed $data): void
    {
        $session = $this->session([$this->token('t'), $this->answer($data)]);

        $this->expectException(IdokladFailure::class);
        $this->expectExceptionMessageIsOrContains('iDoklad returned a list in an unknown shape.');
        iterator_to_array($session->pages('/Contacts'));
    }

    public function testTheClientOpensASessionWithTheCredentials(): void
    {
        $http = new MockHttpClient([$this->token('t'), $this->answer(1)]);
        $session = new HttpIdokladClient($http, $this->clock)->session('example-client', 'example-secret');

        self::assertInstanceOf(HttpIdokladSession::class, $session);
        self::assertSame(1, $session->get('/Contacts'));
        self::assertSame(2, $http->getRequestsCount());
    }

    /**
     * @param list<ResponseInterface> $answers
     */
    private function session(array $answers): HttpIdokladSession
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): ResponseInterface {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($answers) ?? throw new TransportException('No answer is left.');
        });

        return new HttpIdokladSession($http, $this->clock, 'example-client', 'example-secret');
    }

    /**
     * @return list<string>
     */
    private function headers(int $request): array
    {
        $headers = $this->sent[$request]['options']['headers'] ?? [];
        \assert(\is_array($headers));

        return array_values(array_filter($headers, \is_string(...)));
    }

    private function token(string $token, int $expiresIn = 3600): JsonMockResponse
    {
        return new JsonMockResponse(['access_token' => $token, 'expires_in' => $expiresIn, 'token_type' => 'Bearer', 'scope' => 'idoklad_api']);
    }

    private function answer(mixed $data): JsonMockResponse
    {
        return new JsonMockResponse(['Data' => $data, 'ErrorCode' => 0, 'IsSuccess' => true, 'Message' => '', 'StatusCode' => 200]);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function page(array $items, int $pages): JsonMockResponse
    {
        return $this->answer(['Items' => $items, 'TotalItems' => \count($items), 'TotalPages' => $pages]);
    }
}
