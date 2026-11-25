<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Http;

use Foresi\Shared\Application\Http\ContentSecurityPolicySource;
use Foresi\Shared\Infrastructure\Http\ContentSecurityPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentSecurityPolicyTest extends TestCase
{
    public function testWithoutANonceNoInlineScriptOrStyleRuns(): void
    {
        self::assertSame(
            "default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'",
            new ContentSecurityPolicy([])->header(null),
        );
    }

    public function testAModuleAddsHostsWithoutEditingTheCorePolicy(): void
    {
        $policy = new ContentSecurityPolicy([
            $this->source(['connect-src' => ['https://api.example.com'], 'img-src' => ['https://cdn.example.com', "'self'"]]),
            $this->source(['connect-src' => ['https://api.example.com', 'wss://push.example.com'], 'form-action' => ['https://pay.example.com/checkout']]),
        ], '/csp-report');

        self::assertSame(
            "default-src 'self'; script-src 'self' 'nonce-abc' 'strict-dynamic'; style-src 'self' 'nonce-abc'; style-src-attr 'unsafe-inline'; img-src 'self' data: https://cdn.example.com; object-src 'none'; base-uri 'none'; form-action 'self' https://pay.example.com/checkout; frame-ancestors 'self'; connect-src 'self' https://api.example.com wss://push.example.com; report-uri /csp-report",
            $policy->header('abc'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'unsafe-inline' => ['script-src', "'unsafe-inline'"];
        yield 'unsafe-eval' => ['script-src', "'unsafe-eval'"];
        yield 'any host' => ['img-src', '*'];
        yield 'any https host' => ['connect-src', 'https:'];
        yield 'plain http' => ['connect-src', 'http://api.example.com'];
        yield 'data scripts' => ['script-src', 'data:'];
        yield 'another directive' => ['https://api.example.com', 'https://api.example.com'];
        yield 'a directive outside the list' => ['frame-ancestors', 'https://example.com'];
        yield 'an injected directive' => ['connect-src', 'https://api.example.com;script-src'];
        yield 'two sources in one' => ['connect-src', 'https://a.example.com https://b.example.com'];
        yield 'empty' => ['img-src', ''];
    }

    #[DataProvider('refused')]
    public function testASourceThatWeakensThePolicyIsRefused(string $directive, string $value): void
    {
        $this->expectException(\LogicException::class);

        new ContentSecurityPolicy([$this->source([$directive => [$value]])])->header(null);
    }

    /**
     * @param array<string, list<string>> $sources
     */
    private function source(array $sources): ContentSecurityPolicySource
    {
        return new readonly class($sources) implements ContentSecurityPolicySource {
            /**
             * @param array<string, list<string>> $sources
             */
            public function __construct(private array $sources)
            {
            }

            public function sources(): array
            {
                return $this->sources;
            }
        };
    }
}
