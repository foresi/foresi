<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\AuthenticationResultsParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthenticationResultsParserTest extends TestCase
{
    public function testMethodsResultsAndPropertiesAreRead(): void
    {
        self::assertSame([
            'authserv' => 'mx.example.com',
            'results' => [
                ['method' => 'dkim', 'result' => 'pass', 'props' => ['header.d' => 'kb.cz', 'header.s' => 'sel1']],
                ['method' => 'spf', 'result' => 'pass', 'props' => ['smtp.mailfrom' => 'kb.cz']],
                ['method' => 'dmarc', 'result' => 'pass', 'props' => ['header.from' => 'kb.cz']],
            ],
        ], new AuthenticationResultsParser()->parse(
            'mx.example.com; dkim=pass header.d=kb.cz header.s=sel1; spf=pass smtp.mailfrom=kb.cz; dmarc=pass header.from=kb.cz',
        ));
    }

    public function testCommentsVersionsAndCaseAreTolerated(): void
    {
        self::assertSame([
            'authserv' => 'mx.example.com',
            'results' => [
                ['method' => 'dkim', 'result' => 'pass', 'props' => ['reason' => 'good; really', 'header.d' => 'KB.CZ', 'header.b' => 'abc']],
                ['method' => 'arc', 'result' => 'none', 'props' => []],
            ],
        ], new AuthenticationResultsParser()->parse(
            '(by the inbox (v2)) MX.Example.COM (id) 1 ; DKIM/1 (sig) = Pass (ok) Reason = "good; really" Header . D = KB.CZ header.b="abc";  ARC=None',
        ));
    }

    public function testACommentHidesWhatItHolds(): void
    {
        self::assertSame(
            ['authserv' => 'attacker.example', 'results' => [['method' => 'dkim', 'result' => 'fail', 'props' => []]]],
            new AuthenticationResultsParser()->parse('(mx.example.com) attacker.example; dkim=fail (dkim=pass header.d=kb.cz)'),
        );
    }

    public function testAnEscapedQuoteStaysInItsValue(): void
    {
        self::assertSame(
            ['authserv' => 'mx.example.com', 'results' => [['method' => 'dkim', 'result' => 'fail', 'props' => ['reason' => 'a "b"; dkim=pass', 'header.d' => 'kb.cz']]]],
            new AuthenticationResultsParser()->parse('mx.example.com; dkim=fail reason="a \"b\"; dkim=pass" header.d=kb.cz'),
        );
    }

    public function testAQuotedAuthservIdIsUnquoted(): void
    {
        self::assertSame('mx.example.com', new AuthenticationResultsParser()->parse('"mx.example.com"; none')['authserv']);
    }

    public function testTheFirstOfARepeatedPropertyCounts(): void
    {
        self::assertSame(
            [['method' => 'dkim', 'result' => 'pass', 'props' => ['header.d' => 'kb.cz']]],
            new AuthenticationResultsParser()->parse('mx.example.com; dkim=pass header.d=kb.cz header.d=example.com')['results'],
        );
    }

    /**
     * @return iterable<string, array{string, string, list<array{method: string, result: string, props: array<string, string>}>}>
     */
    public static function sparse(): iterable
    {
        yield 'no result' => ['mx.example.com; none', 'mx.example.com', []];
        yield 'nothing after the id' => ['mx.example.com', 'mx.example.com', []];
        yield 'empty' => ['', '', []];
        yield 'only a comment' => ['(nothing here)', '', []];
        yield 'an empty result between two' => ['mx.example.com; ; dkim=none', 'mx.example.com', [['method' => 'dkim', 'result' => 'none', 'props' => []]]];
        yield 'a result without a method' => ['mx.example.com; =pass; dkim=none', 'mx.example.com', [['method' => 'dkim', 'result' => 'none', 'props' => []]]];
        yield 'a property without a value' => ['mx.example.com; dkim=pass header.d=', 'mx.example.com', [['method' => 'dkim', 'result' => 'pass', 'props' => []]]];
        yield 'an unclosed comment' => ['mx.example.com; dkim=pass (header.d=kb.cz', 'mx.example.com', [['method' => 'dkim', 'result' => 'pass', 'props' => []]]];
        yield 'an unclosed quote' => ['mx.example.com; dkim=pass reason="x; dmarc=pass', 'mx.example.com', [['method' => 'dkim', 'result' => 'pass', 'props' => ['reason' => 'x; dmarc=pass']]]];
    }

    /**
     * @param list<array{method: string, result: string, props: array<string, string>}> $results
     */
    #[DataProvider('sparse')]
    public function testSparseOrBrokenHeadersGiveWhatTheyHold(string $header, string $authserv, array $results): void
    {
        self::assertSame(['authserv' => $authserv, 'results' => $results], new AuthenticationResultsParser()->parse($header));
    }

    public function testTagsOfAnArcHeaderAreRead(): void
    {
        self::assertSame(
            ['i' => '2', 'a' => 'rsa-sha256', 'cv' => 'pass', 'd' => 'arc.example.com', 's' => 'arc1', 'b' => 'ZmFr ZQ=='],
            new AuthenticationResultsParser()->tags(' i = 2; a=rsa-sha256; cv=pass; D=arc.example.com; s=arc1; b=ZmFr ZQ==; ; junk; i=3'),
        );
    }
}
