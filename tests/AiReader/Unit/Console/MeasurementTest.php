<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\AiReader\Unit\Console;

use Foresi\AiReader\Application\AiReaderSettings;
use Foresi\AiReader\Infrastructure\Console\Measurement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What ai-reader:measure reads for each file: the values, the tokens the provider reports, the model of the
 * run and the failure code, and never the key.
 */
final class MeasurementTest extends TestCase
{
    private const string KEY = 'sk-test-not-a-real-key';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ai-reader-measure-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $found = glob($this->directory.'/*');
        foreach (\is_array($found) ? $found : [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testFilesAreThePdfPngAndJpegOnesSortedByName(): void
    {
        foreach (['b.pdf', 'a.PNG', 'c.jpeg', 'notes.txt', 'd.xml'] as $name) {
            file_put_contents($this->directory.'/'.$name, 'example');
        }

        $bodies = [];
        $files = $this->measurement($bodies)->files($this->directory);

        self::assertSame(['a.PNG', 'b.pdf', 'c.jpeg'], array_map(basename(...), $files));
    }

    public function testReadsOneFileWithTheTokensTheModelAndNoKey(): void
    {
        file_put_contents($this->directory.'/invoice.pdf', '%PDF-1.4 example');
        $bodies = [];
        $answer = ['supplier_name' => 'Supplier Example s.r.o.', 'supplier_registration_number' => '12345678', 'supplier_vat_id' => 'CZ12345678', 'issue_date' => '2026-10-28', 'currency' => 'CZK', 'total' => '121.00', 'vat_recap' => [['rate' => '21', 'base' => '100.00', 'vat' => '21.00']]];

        $line = $this->measurement($bodies, $this->message($answer, ['input_tokens' => 1500, 'output_tokens' => 120]))->read($this->directory.'/invoice.pdf', 'claude-example-large');

        self::assertSame('claude-example-large', $bodies[0]['model']);
        self::assertSame('invoice.pdf', $line['file']);
        self::assertGreaterThanOrEqual(0, $line['ms']);
        self::assertSame(1500, $line['input_tokens']);
        self::assertSame(120, $line['output_tokens']);
        self::assertNull($line['error']);
        self::assertSame('Supplier Example s.r.o.', $line['supplier_name']);
        self::assertSame('12345678', $line['supplier_registration_number']);
        self::assertSame('CZ12345678', $line['supplier_vat_id']);
        self::assertSame(12100, $line['total_minor']);
        self::assertSame('CZK', $line['currency']);
        self::assertSame('2026-10-28', $line['issue_date']);
        self::assertSame([['rate' => '21', 'baseMinor' => 10000, 'vatMinor' => 2100]], $line['vat_recap']);
        self::assertStringNotContainsString(self::KEY, json_encode($line, \JSON_THROW_ON_ERROR));
    }

    public function testTheDefaultModelIsUsedWhenNoneIsAsked(): void
    {
        file_put_contents($this->directory.'/invoice.pdf', '%PDF-1.4 example');
        $bodies = [];

        $this->measurement($bodies, $this->message(['total' => '1.00'], ['input_tokens' => 1, 'output_tokens' => 1]))->read($this->directory.'/invoice.pdf');

        self::assertSame('claude-sonnet-5-5', $bodies[0]['model']);
    }

    public function testAFailureIsTheCodeAndNothingElse(): void
    {
        file_put_contents($this->directory.'/invoice.pdf', '%PDF-1.4 example');
        $bodies = [];

        $line = $this->measurement($bodies, new MockResponse('{"error":{"message":"secret '.self::KEY.'"}}', ['http_code' => 429]))->read($this->directory.'/invoice.pdf');

        self::assertSame('provider_busy', $line['error']);
        self::assertNull($line['input_tokens']);
        self::assertNull($line['supplier_name']);
        self::assertStringNotContainsString(self::KEY, json_encode($line, \JSON_THROW_ON_ERROR));
    }

    public function testAFileTheReaderCannotTakeIsAFailureToo(): void
    {
        file_put_contents($this->directory.'/scan.png', 'not really a png');

        $bodies = [];
        $line = $this->measurement($bodies)->read($this->directory.'/scan.png');

        self::assertSame('unsupported_file', $line['error']);
    }

    /**
     * @param list<array<string, mixed>> $bodies the JSON bodies of the requests, filled as they are made
     */
    private function measurement(array &$bodies, MockResponse ...$responses): Measurement
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, &$responses): MockResponse {
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true);
            $bodies[] = \is_array($body) ? $body : [];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new Measurement(new AiReaderSettings('claude', self::KEY, '', ''), $http);
    }

    /**
     * @param array<string, mixed> $answer
     * @param array<string, int>   $usage
     */
    private function message(array $answer, array $usage): MockResponse
    {
        return new MockResponse(json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($answer, \JSON_THROW_ON_ERROR)]], 'usage' => $usage], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
