<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\Value;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueViewTest extends TestCase
{
    private const array NOTHING = ['kind' => 'empty', 'text' => '', 'badges' => [], 'hint' => null, 'links' => []];

    /**
     * @return iterable<string, array{mixed, array<string, mixed>}>
     */
    public static function shapes(): iterable
    {
        yield 'nothing' => [null, self::NOTHING];
        yield 'an empty text' => ['', self::NOTHING];
        yield 'an empty list' => [[], self::NOTHING];
        yield 'no badges' => [['badges' => []], self::NOTHING];
        yield 'a number as text' => [42, ['kind' => 'text', 'text' => '42'] + self::NOTHING];
        yield 'a badge' => [['label' => 'Paid', 'tone' => 'green', 'hint' => 'Today'], ['kind' => 'badge', 'text' => 'Paid', 'badges' => [['label' => 'Paid', 'tone' => 'green']], 'hint' => 'Today'] + self::NOTHING];
        yield 'a badge by its text' => [['text' => 'Paid', 'tone' => 'green'], ['kind' => 'badge', 'text' => 'Paid', 'badges' => [['label' => 'Paid', 'tone' => 'green']]] + self::NOTHING];
        yield 'badges keyed by value' => [['badges' => ['a' => ['label' => 'Customer'], 'b' => ['label' => 'Supplier', 'tone' => 'green']]], ['kind' => 'badges', 'text' => 'Customer, Supplier', 'badges' => [['label' => 'Customer', 'tone' => 'gray'], ['label' => 'Supplier', 'tone' => 'green']]] + self::NOTHING];
        yield 'links' => [['links' => [['text' => 'a@example.com', 'href' => 'mailto:a@example.com'], ['text' => 'Invoice', 'href' => '/invoices/1', 'opens' => 'sheet']]], ['kind' => 'links', 'text' => 'a@example.com, Invoice', 'links' => [['text' => 'a@example.com', 'href' => 'mailto:a@example.com', 'opens' => 'page'], ['text' => 'Invoice', 'href' => '/invoices/1', 'opens' => 'sheet']]] + self::NOTHING];
    }

    /**
     * @param array<string, mixed> $view
     */
    #[DataProvider('shapes')]
    public function testEachShapeIsShownAsItsKind(mixed $value, array $view): void
    {
        self::assertSame($view, Value::view($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'an object' => [new \stdClass()];
        yield 'badges that are no list' => [['badges' => 'Customer']];
        yield 'links that are no list' => [['links' => 'a@example.com']];
        yield 'no links' => [['links' => []]];
        yield 'a link that is no array' => [['links' => ['a@example.com']]];
        yield 'an unsafe link' => [['links' => [['text' => 'x', 'href' => 'javascript:alert(1)']]]];
        yield 'a link that opens nowhere' => [['links' => [['text' => 'x', 'href' => '/x', 'opens' => 'tab']]]];
        yield 'a hint that is no text' => [['label' => 'Paid', 'hint' => 3]];
        yield 'a badge of an unknown tone' => [['label' => 'Paid', 'tone' => 'blue']];
    }

    #[DataProvider('invalid')]
    public function testAnythingElseIsRefused(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Value::view($value);
    }
}
