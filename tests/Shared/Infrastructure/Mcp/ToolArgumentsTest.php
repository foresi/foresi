<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use App\Shared\Infrastructure\Api\Cursor;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolRefusal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * The readers, schemas and search answers the read tools share.
 */
final class ToolArgumentsTest extends TestCase
{
    private const string ID = '01963c5e-0000-7000-8000-000000000001';

    public function testTheReadersAnswerTheValueOrTheDefault(): void
    {
        $arguments = ['text' => 'Alpha', 'flag' => true, 'limit' => 7, 'none' => null];

        self::assertSame('Alpha', ToolArguments::text($arguments, 'text'));
        self::assertNull(ToolArguments::text($arguments, 'missing'));
        self::assertNull(ToolArguments::text($arguments, 'none'));
        self::assertTrue(ToolArguments::flag($arguments, 'flag'));
        self::assertFalse(ToolArguments::flag(['flag' => false], 'flag'));
        self::assertFalse(ToolArguments::flag($arguments, 'missing'));
        self::assertSame(7, ToolArguments::limit($arguments));
        self::assertSame(25, ToolArguments::limit([]));
    }

    public function testTheCursorIsReadBackAsTheIdItWasMadeOf(): void
    {
        self::assertSame(self::ID, ToolArguments::afterId(Cursor::encode(self::ID)));
        self::assertNull(ToolArguments::afterId(null));
        self::assertNull(ToolArguments::afterId(''));
        try {
            ToolArguments::afterId(Cursor::encode('not-an-id'));
            self::fail('A cursor no search gave was read.');
        } catch (ToolRefusal $refusal) {
            self::assertSame(ToolRefusal::INVALID_ARGUMENT, $refusal->errorCode);
        }
    }

    public function testAQueryObjectIsValidatedWithTheEnglishMessageAndTheArgumentName(): void
    {
        $arguments = new ToolArguments(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $valid = new class(5) {
            public function __construct(#[Assert\Range(min: 1, max: 10)] public int $pageSize)
            {
            }
        };
        $arguments->validate($valid);

        try {
            $arguments->validate(new $valid(11));
            self::fail('An argument out of range was accepted.');
        } catch (ToolRefusal $refusal) {
            self::assertSame('invalid_argument', $refusal->errorCode);
            self::assertSame('The argument "page_size" cannot be used: This value should be between 1 and 10.', $refusal->getMessage());
        }
    }

    public function testTheSchemasOfTheCommonArguments(): void
    {
        self::assertSame(['type' => 'string', 'format' => 'uuid', 'description' => 'The firm.'], ToolArguments::idSchema('The firm.'));
        self::assertSame(['type' => 'string', 'format' => 'date', 'description' => 'From. As YYYY-MM-DD, the day included.'], ToolArguments::dateSchema('From.'));
        self::assertSame(['type' => 'string', 'enum' => ['a', 'b'], 'description' => 'One.'], ToolArguments::choiceSchema(['a', 'b'], 'One.'));
        $paging = ToolArguments::pagingSchema();
        self::assertSame(['limit', 'cursor'], array_keys($paging));
        self::assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25, 'description' => 'How many to return at most.'], $paging['limit']);
        self::assertSame('string', $paging['cursor']['type']);
    }

    public function testASearchSaysHowManyItHoldsAndWhetherMoreMatch(): void
    {
        $none = ToolArguments::page([], null, 'invoice', 'invoices');
        self::assertSame(['items' => [], 'next_cursor' => null], $none->data);
        self::assertSame('No invoices match.', $none->summary);

        $one = ToolArguments::page([['id' => self::ID]], null, 'invoice', 'invoices');
        self::assertSame(['items' => [['id' => self::ID]], 'next_cursor' => null], $one->data);
        self::assertSame('1 invoice, newest first; no more match.', $one->summary);

        $more = ToolArguments::page([['id' => 'a'], ['id' => self::ID]], self::ID, 'invoice', 'invoices');
        self::assertSame(Cursor::encode(self::ID), $more->data['next_cursor']);
        self::assertSame('2 invoices, newest first; more match: call again with the same arguments and next_cursor as cursor.', $more->summary);
    }

    public function testAForbiddenReadHasItsCode(): void
    {
        $refusal = ToolRefusal::forbidden('No.');

        self::assertSame('forbidden', $refusal->errorCode);
        self::assertSame('No.', $refusal->getMessage());
    }
}
