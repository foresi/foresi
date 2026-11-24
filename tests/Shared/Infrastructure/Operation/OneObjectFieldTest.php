<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Infrastructure\Operation\InputMapper;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\InvalidInput;
use Foresi\Shared\Infrastructure\Operation\OperationDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A field that is one JSON object (Field::ONE_OBJECT), such as the mapping of a planned item: what the mapper takes
 * and what the schema says.
 */
final class OneObjectFieldTest extends TestCase
{
    private const string ID = '0197a1de-0000-7000-8000-000000000001';

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function objects(): iterable
    {
        yield 'an object' => [['kind' => 'whole', 'shares' => [['recipient' => 'own']]]];
        // An empty JSON object, {}, decodes to an empty array.
        yield 'an empty object' => [[]];
        yield 'null' => [null];
    }

    #[DataProvider('objects')]
    public function testAnObjectOrNullIsTakenAsSent(mixed $mapping): void
    {
        $input = InputMapper::map($this->definition(), ['thing_id' => self::ID, 'mapping' => $mapping]);

        self::assertInstanceOf(MappingThingInput::class, $input);
        self::assertSame($mapping, $input->mapping);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function others(): iterable
    {
        yield 'a list of objects' => [[['kind' => 'whole']]];
        yield 'a string' => ['whole'];
        yield 'a number' => [3];
        yield 'true' => [true];
    }

    #[DataProvider('others')]
    public function testAnythingElseIsRefused(mixed $mapping): void
    {
        try {
            InputMapper::map($this->definition(), ['thing_id' => self::ID, 'mapping' => $mapping]);
            self::fail('The input was taken.');
        } catch (InvalidInput $invalid) {
            self::assertSame('mapping', $invalid->wire);
            self::assertSame('The field "mapping" must be an object.', $invalid->getMessage());
        }
    }

    public function testTheSchemaSaysItIsOneObject(): void
    {
        $fields = array_column($this->definition()->fields, null, 'wire');
        $property = InputSchema::property($fields['mapping']);

        self::assertSame(['object', 'null'], $property['type']);
        self::assertArrayNotHasKey('items', $property);
    }

    private function definition(): OperationDefinition
    {
        return OperationDefinition::of(new MappingThingHandler());
    }
}
