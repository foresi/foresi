<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Domain;

use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\ReadingState;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentContent;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentReadingTest extends TestCase
{
    private const string AT = '2026-11-10 10:00:00';

    public function testAFailureIsAShortCodeOfLowerCaseLettersAndUnderscores(): void
    {
        foreach (['reader_failed', 'a', '_', str_repeat('a', DocumentReading::MAX_CODE_LENGTH)] as $code) {
            $reading = $this->reading();
            $reading->fail($code, new \DateTimeImmutable(self::AT));

            self::assertSame(ReadingState::Failed, $reading->state(), $code);
        }
    }

    #[DataProvider('badCodes')]
    public function testAnythingElseIsRefused(string $code): void
    {
        $reading = $this->reading();

        try {
            $reading->fail($code, new \DateTimeImmutable(self::AT));
            self::fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('A failure is a short code.', $e->getMessage());
        }
        self::assertSame(ReadingState::Reading, $reading->state());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'one too long' => [str_repeat('a', DocumentReading::MAX_CODE_LENGTH + 1)];
        yield 'upper case' => ['ABC'];
        yield 'a digit after letters' => ['abc1'];
        yield 'a digit before letters' => ['1abc'];
        yield 'a trailing newline' => ["abc\n"];
        yield 'a space' => ['a b'];
    }

    private function reading(): DocumentReading
    {
        $document = new ReceivedDocument(ReceivedDocumentContent::blank(new \DateTimeImmutable('2026-11-02')), Id::generate(), new \DateTimeImmutable(self::AT));

        return new DocumentReading($document, 1, new \DateTimeImmutable(self::AT));
    }
}
