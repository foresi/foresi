<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Application\View\CreateViewInput;
use Foresi\Shared\Infrastructure\Operation\InputMapper;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\InvalidInput;
use Foresi\Shared\Infrastructure\Operation\OperationDefinition;
use Foresi\Shared\Infrastructure\View\Operation\CreateViewHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list of JSON objects, such as the conditions of a saved view, and a list
 * that mixes objects and strings, such as its columns: what the mapper takes
 * and what the schema says.
 */
final class ObjectListFieldTest extends TestCase
{
    public function testListsOfObjectsAreTakenAsSent(): void
    {
        $condition = ['field' => 'due_date', 'op' => 'is', 'value' => ['relative' => 'today']];
        $input = InputMapper::map(self::definition(), ['list_key' => 'invoicing.invoices', 'name' => 'Due', 'conditions' => [$condition, []], 'columns' => ['number', ['key' => 'customer', 'width' => 200]]]);

        self::assertInstanceOf(CreateViewInput::class, $input);
        self::assertSame([$condition, []], $input->conditions);
        self::assertSame(['number', ['key' => 'customer', 'width' => 200]], $input->columns);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function badLists(): iterable
    {
        yield 'a string among objects' => [['conditions' => ['due_date']], 'conditions', 'The field "conditions" must be a list of objects.'];
        yield 'a list for an object' => [['sort' => [['due_date', 'asc']]], 'sort', 'The field "sort" must be a list of objects.'];
        yield 'an object for a list' => [['conditions' => ['field' => 'due_date']], 'conditions', 'The field "conditions" must be a list of objects.'];
        yield 'a number among columns' => [['columns' => ['number', 3]], 'columns', 'The field "columns" must be a list of objects or strings.'];
    }

    /**
     * @param array<string, mixed> $sent
     */
    #[DataProvider('badLists')]
    public function testOtherItemsAreRefused(array $sent, string $field, string $message): void
    {
        try {
            InputMapper::map(self::definition(), $sent + ['list_key' => 'invoicing.invoices', 'name' => 'Due']);
            self::fail('The input was taken.');
        } catch (InvalidInput $invalid) {
            self::assertSame($field, $invalid->wire);
            self::assertSame($message, $invalid->getMessage());
        }
    }

    public function testTheSchemaSaysWhatTheItemsAre(): void
    {
        $fields = array_column(self::definition()->fields, null, 'wire');

        self::assertSame(['type' => 'object'], InputSchema::property($fields['conditions'])['items']);
        $columns = InputSchema::property($fields['columns']);
        self::assertSame(['anyOf' => [['type' => 'object'], ['type' => 'string']]], $columns['items']);
        self::assertSame(['array', 'null'], $columns['type']);
    }

    private static function definition(): OperationDefinition
    {
        return OperationDefinition::of(new \ReflectionClass(CreateViewHandler::class)->newInstanceWithoutConstructor());
    }
}
