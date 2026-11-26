<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\StatementFormats;
use Foresi\Banking\Contract\ParseFailed;
use Foresi\Banking\Contract\ParseFailure;
use Foresi\Banking\Contract\StatementFile;
use Foresi\Banking\Contract\StatementSequence;
use Foresi\Tests\Fixtures\Banking\Statement\JsonStatementFormat;
use PHPUnit\Framework\TestCase;

final class StatementFormatsTest extends TestCase
{
    private const string FILE = '{"test-statement": 1, "statements": [{"account": "CZ6508000000192000145399", "currency": "CZK", "id": "S-42", "number": 42, "year": 2026, "from": "2026-11-01", "to": "2026-11-30", "opening": "1000.00", "closing": "1210.00", "entries": [{"direction": "incoming", "amount": "210.00", "booked": "2026-11-20", "vs": "20260001", "ref": "L-1"}]}]}';

    public function testTheFormatThatSupportsTheFileReadsIt(): void
    {
        $formats = new StatementFormats([new JsonStatementFormat('test.z'), new JsonStatementFormat('test.a')]);
        $file = new StatementFile('statement.json', self::FILE);

        self::assertSame(['test.a', 'test.z'], array_map(static fn (\Foresi\Banking\Contract\StatementFormat $format): string => $format->id(), $formats->all()));
        $this->expectExceptionObject(new ParseFailed(ParseFailure::Ambiguous, 'The formats test.a, test.z all read this file.'));
        $formats->for($file);
    }

    public function testOneFormatReadsTheStatement(): void
    {
        $format = new StatementFormats([new JsonStatementFormat()])->for(new StatementFile('statement.json', self::FILE));

        self::assertInstanceOf(JsonStatementFormat::class, $format);
        $statements = $format->read(new StatementFile('statement.json', self::FILE));
        self::assertCount(1, $statements);
        self::assertEquals(StatementSequence::withinYear(42, 2026), $statements[0]->sequence);
        self::assertSame('S-42', $statements[0]->statementId);
        self::assertSame(121000, $statements[0]->closingBalance?->minor());
        self::assertSame(100000, $statements[0]->openingBalance?->minor());
        self::assertSame('L-1', $statements[0]->entries[0]->bankReference);
    }

    public function testNoFormatForAnotherFile(): void
    {
        self::assertNull(new StatementFormats([new JsonStatementFormat()])->for(new StatementFile('statement.xml', '<Document/>')));
    }

    public function testTwoFormatsOfOneIdAreAMistake(): void
    {
        $this->expectException(\LogicException::class);
        new StatementFormats([new JsonStatementFormat(), new JsonStatementFormat()]);
    }
}
