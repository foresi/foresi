<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Field;

use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldExpression;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\FieldSource;
use Foresi\Shared\Infrastructure\Field\FieldRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The registry over the field sources: one source owns a key, values are read once a
 * request and kept current by writes, and a value is checked against its kind.
 */
final class FieldRegistryTest extends TestCase
{
    private const string ID = '0192f1a4-0000-7000-8000-000000000001';
    private const string OTHER = '0192f1a4-0000-7000-8000-000000000002';

    public function testTheDefinitionsOfEverySourceAreTheRecordsFields(): void
    {
        $registry = new FieldRegistry([$this->source(['a_text' => FieldKind::Text]), $this->source(['b_flag' => FieldKind::Boolean])]);

        self::assertSame(['a_text', 'b_flag'], array_map(static fn (FieldDefinition $definition): string => $definition->key, $registry->definitions('note')));
        self::assertSame(FieldKind::Boolean, $registry->definition('note', 'b_flag')?->kind);
        self::assertNull($registry->definition('note', 'c_none'));
        self::assertSame([], $registry->definitions('other'));
    }

    public function testTwoSourcesOfOneKeyAreRefused(): void
    {
        $registry = new FieldRegistry([$this->source(['a_text' => FieldKind::Text]), $this->source(['a_text' => FieldKind::Text])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Two field sources declare the field "a_text" of the note.');

        $registry->definitions('note');
    }

    public function testValuesAreReadOnceARequest(): void
    {
        $source = $this->source(['a_text' => FieldKind::Text], [self::ID => ['a_text' => 'one']]);
        $registry = new FieldRegistry([$source]);

        self::assertSame([self::ID => ['a_text' => 'one'], self::OTHER => []], $registry->values('note', [self::ID, self::OTHER]));
        self::assertSame('one', $registry->value('note', self::ID, 'a_text'));
        self::assertNull($registry->value('note', self::OTHER, 'a_text'));
        self::assertSame([[self::ID, self::OTHER]], $source->reads);

        $registry->reset();
        self::assertSame('one', $registry->value('note', self::ID, 'a_text'));
        self::assertSame([[self::ID, self::OTHER], [self::ID]], $source->reads, 'A value not read yet is read with its record.');
    }

    public function testAWriteGoesToTheSourceOfEachKeyAndKeepsTheValuesCurrent(): void
    {
        $text = $this->source(['a_text' => FieldKind::Text], [self::ID => ['a_text' => 'one']]);
        $flag = $this->source(['b_flag' => FieldKind::Boolean]);
        $registry = new FieldRegistry([$text, $flag]);
        $registry->values('note', [self::ID]);

        $registry->write('note', self::ID, ['a_text' => '', 'b_flag' => true]);

        self::assertSame([['note', self::ID, ['a_text' => null]]], $text->writes);
        self::assertSame([['note', self::ID, ['b_flag' => true]]], $flag->writes);
        self::assertSame([self::ID => ['b_flag' => true]], $registry->values('note', [self::ID]));
        $registry->write('note', self::OTHER, ['b_flag' => false]);
        self::assertSame([], $registry->values('note', [self::OTHER])[self::OTHER], 'A record not read yet is read from its source, which has not flushed.');
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('accepted')]
    public function testAValueIsKeptAsItsKindKeepsIt(FieldKind $kind, mixed $value, mixed $kept, array $options = []): void
    {
        $source = $this->source(['a_field' => $kind], [], $options);
        new FieldRegistry([$source])->write('note', self::ID, ['a_field' => $value]);

        self::assertEquals([['note', self::ID, ['a_field' => $kept]]], $source->writes);
    }

    /**
     * @return iterable<string, array{0: FieldKind, 1: mixed, 2: mixed, 3?: array<string, string>}>
     */
    public static function accepted(): iterable
    {
        $options = ['red' => 'test.red', 'blue' => 'test.blue'];

        yield 'a text' => [FieldKind::Text, 'Hello', 'Hello'];
        yield 'an empty text is none' => [FieldKind::LongText, '', null];
        yield 'a web address' => [FieldKind::Url, 'https://example.com', 'https://example.com'];
        yield 'a contact' => [FieldKind::Contact, self::OTHER, self::OTHER];
        yield 'a user' => [FieldKind::User, self::OTHER, self::OTHER];
        yield 'a whole number' => [FieldKind::Number, 3, 3];
        yield 'a fraction' => [FieldKind::Number, 0.5, 0.5];
        yield 'a decimal as text' => [FieldKind::Number, '12.50', '12.50'];
        yield 'a date' => [FieldKind::Date, new \DateTimeImmutable('2027-01-31'), new \DateTimeImmutable('2027-01-31')];
        yield 'a mutable time is kept immutable' => [FieldKind::DateTime, new \DateTime('2026-11-25 10:30'), new \DateTimeImmutable('2026-11-25 10:30')];
        yield 'false' => [FieldKind::Boolean, false, false];
        yield 'an option' => [FieldKind::Select, 'red', 'red', $options];
        yield 'options, each once' => [FieldKind::MultiSelect, ['blue', 'red', 'blue'], ['blue', 'red'], $options];
        yield 'no options is none' => [FieldKind::MultiSelect, [], null, $options];
        yield 'null' => [FieldKind::Number, null, null];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('refused')]
    public function testAValueItsKindDoesNotTakeIsRefused(FieldKind $kind, mixed $value, string $message, array $options = []): void
    {
        $registry = new FieldRegistry([$this->source(['a_field' => $kind], [], $options)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $registry->write('note', self::ID, ['a_field' => $value]);
    }

    /**
     * @return iterable<string, array{0: FieldKind, 1: mixed, 2: string, 3?: array<string, string>}>
     */
    public static function refused(): iterable
    {
        $options = ['red' => 'test.red', 'blue' => 'test.blue'];

        yield 'a number for a text' => [FieldKind::Text, 5, 'The field "a_field" takes a text.'];
        yield 'a word for a number' => [FieldKind::Number, 'five', 'The field "a_field" takes a number.'];
        yield 'true for a number' => [FieldKind::Number, true, 'The field "a_field" takes a number.'];
        yield 'a text for a date' => [FieldKind::Date, '2027-01-31', 'The field "a_field" takes a date.'];
        yield 'a number for a boolean' => [FieldKind::Boolean, 1, 'The field "a_field" takes true or false.'];
        yield 'an unknown option' => [FieldKind::Select, 'green', 'The field "a_field" takes one of red, blue.', $options];
        yield 'a list for a select' => [FieldKind::Select, ['red'], 'The field "a_field" takes one of red, blue.', $options];
        yield 'an unknown option of a set' => [FieldKind::MultiSelect, ['red', 'green'], 'The field "a_field" takes a list of red, blue.', $options];
        yield 'one option for a set' => [FieldKind::MultiSelect, 'red', 'The field "a_field" takes a list of red, blue.', $options];
        yield 'a map for a set' => [FieldKind::MultiSelect, ['a' => 'red'], 'The field "a_field" takes a list of red, blue.', $options];
        yield 'a number in a set' => [FieldKind::MultiSelect, [1], 'The field "a_field" takes a list of red, blue.', $options];
    }

    public function testAKeyOfNoFieldIsRefused(): void
    {
        $registry = new FieldRegistry([$this->source(['a_text' => FieldKind::Text])]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The note has no custom field "b_none".');

        $registry->write('note', self::ID, ['b_none' => 'x']);
    }

    public function testAnExpressionTakesItsKeysAliasUnlessItIsGivenOne(): void
    {
        $registry = new FieldRegistry([$this->source(['a_text' => FieldKind::Text])]);

        self::assertEquals(new FieldExpression('Note', 'cf_a_text', 'cf_a_text.id = n.id', 'cf_a_text.aText'), $registry->expression('note', 'a_text', 'n'));
        self::assertEquals(new FieldExpression('Note', 'x', 'x.id = n.id', 'x.aText'), $registry->expression('note', 'a_text', 'n', 'x'));
    }

    public function testAnExpressionOfNoFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The note has no custom field "b_none".');

        new FieldRegistry([$this->source(['a_text' => FieldKind::Text])])->expression('note', 'b_none', 'n');
    }

    public function testTheSearchReadsTheSearchableFields(): void
    {
        $registry = new FieldRegistry([$this->source(['a_text' => FieldKind::Text, 'b_code' => FieldKind::Text], searchable: ['b_code'])]);

        self::assertSame(["EXISTS (SELECT 1 FROM Note cfs_b_code WHERE cfs_b_code.id = n.id AND LOWER(cfs_b_code.bCode) LIKE :text ESCAPE '!')"], $registry->searched('note', 'n', 'text'));
        self::assertSame([], $registry->searched('other', 'n', 'text'));
    }

    /**
     * A source of the record "note" with these fields, reading these values.
     *
     * @param array<string, FieldKind>            $kinds
     * @param array<string, array<string, mixed>> $values
     * @param array<string, string>               $options
     * @param list<string>                        $searchable
     *
     * @return FieldSource&object{reads: list<list<string>>, writes: list<array{string, string, array<string, mixed>}>}
     */
    private function source(array $kinds, array $values = [], array $options = [], array $searchable = []): FieldSource
    {
        return new class($kinds, $values, $options, $searchable) implements FieldSource {
            /** @var list<list<string>> */
            public array $reads = [];
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $writes = [];

            /**
             * @param array<string, FieldKind>            $kinds
             * @param array<string, array<string, mixed>> $values
             * @param array<string, string>               $options
             * @param list<string>                        $searchable
             */
            public function __construct(private readonly array $kinds, private readonly array $values, private readonly array $options, private readonly array $searchable)
            {
            }

            public function definitions(string $record): array
            {
                if ('note' !== $record) {
                    return [];
                }

                return array_map(fn (FieldKind $kind, string $key): FieldDefinition => new FieldDefinition($key, 'note', $kind, 'test.'.$key, $kind->selects() ? $this->options : [], 'test', searchable: \in_array($key, $this->searchable, true)), $this->kinds, array_keys($this->kinds));
            }

            public function read(string $record, array $ids): array
            {
                $this->reads[] = $ids;

                return array_intersect_key($this->values, array_flip($ids));
            }

            public function write(string $record, string $id, array $values): void
            {
                $this->writes[] = [$record, $id, $values];
            }

            public function expression(string $record, string $key, string $root): FieldExpression
            {
                $alias = 'cf_'.$key;

                return new FieldExpression('Note', $alias, $alias.'.id = '.$root.'.id', $alias.'.'.lcfirst(str_replace('_', '', ucwords($key, '_'))));
            }
        };
    }
}
