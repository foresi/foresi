<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Domain;

use Foresi\Meetings\Domain\MinutesToken;
use Foresi\Shared\Application\TaskReferenceSyntax;
use PHPUnit\Framework\TestCase;

/**
 * Where the token of an action item goes in the minutes: in place of the first
 * exact occurrence of the quote outside another token, or as a list item at the end.
 */
final class MinutesTokenTest extends TestCase
{
    private const string TASK = '0198a3c2-7d1e-7a55-9b0c-4f6d2e8a1b33';
    private const string OTHER = '0198a3c2-7d1e-7a55-9b0c-4f6d2e8a1b44';

    public function testABlankQuoteOrOneOverSeveralLinesIsNotFound(): void
    {
        self::assertNull(MinutesToken::inPlaceOf('Talk about  the offer', '  ', self::TASK));
        self::assertNull(MinutesToken::inPlaceOf("Send the\noffer", "Send the\noffer", self::TASK));
        self::assertNull(MinutesToken::inPlaceOf("Send the offer\r\n", "offer\r", self::TASK));
    }

    public function testTheQuoteIsReplacedAtItsFirstOccurrence(): void
    {
        self::assertSame('- '.TaskReferenceSyntax::token('Call', self::TASK).' Acme, then call Bob', MinutesToken::inPlaceOf('- Call Acme, then call Bob', 'Call', self::TASK));
        self::assertNull(MinutesToken::inPlaceOf('- Call Acme', 'Email', self::TASK));
    }

    public function testAQuoteInsideAnotherTaskTokenIsSkippedForTheNextOccurrenceOutsideIt(): void
    {
        $token = TaskReferenceSyntax::token('Send the offer', self::OTHER);

        self::assertSame($token.' and '.TaskReferenceSyntax::token('offer', self::TASK), MinutesToken::inPlaceOf($token.' and offer', 'offer', self::TASK));
        self::assertNull(MinutesToken::inPlaceOf($token, 'offer', self::TASK));
        self::assertNull(MinutesToken::inPlaceOf($token, 'Send the offer', self::TASK));
        self::assertNull(MinutesToken::inPlaceOf('- '.$token, '- [', self::TASK));
        self::assertNull(MinutesToken::inPlaceOf($token, '(task:'.self::OTHER.')', self::TASK));
    }

    public function testAQuoteTouchingAnotherTaskTokenIsNotInsideIt(): void
    {
        $token = TaskReferenceSyntax::token('Offer', self::OTHER);

        self::assertSame(TaskReferenceSyntax::token('Call', self::TASK).$token, MinutesToken::inPlaceOf('Call'.$token, 'Call', self::TASK));
        self::assertSame($token.TaskReferenceSyntax::token('Call', self::TASK), MinutesToken::inPlaceOf($token.'Call', 'Call', self::TASK));
    }

    public function testASecondOccurrenceStartingInsideAnotherTokenIsFoundOneCharacterLater(): void
    {
        $token = TaskReferenceSyntax::token('Offer', self::OTHER);

        // The first "))" starts on the last character of the token; the next one starts right after it.
        self::assertSame($token.TaskReferenceSyntax::token('))', self::TASK).')', MinutesToken::inPlaceOf($token.')))', '))', self::TASK));
    }

    public function testTheTokenIsAppendedAsAListItem(): void
    {
        $item = '- '.TaskReferenceSyntax::token('Book the venue', self::TASK)."\n";

        self::assertSame($item, MinutesToken::appended('', 'Book the venue', self::TASK));
        self::assertSame($item, MinutesToken::appended("  \n\n", 'Book the venue', self::TASK));
        self::assertSame("- Call the bank\n".$item, MinutesToken::appended('- Call the bank', 'Book the venue', self::TASK));
        self::assertSame("## Next\n\n* Call the bank\n".$item, MinutesToken::appended("## Next\n\n* Call the bank\n\n", 'Book the venue', self::TASK));
        self::assertSame("Call the bank\n\n".$item, MinutesToken::appended('Call the bank', 'Book the venue', self::TASK));
        self::assertSame("Intro\n\nCall the bank\n\n".$item, MinutesToken::appended("Intro\n\nCall the bank\n", 'Book the venue', self::TASK));
    }
}
