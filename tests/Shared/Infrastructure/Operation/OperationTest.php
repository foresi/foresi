<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Infrastructure\Operation\ConfirmationRequired;
use Foresi\Shared\Infrastructure\Operation\InputMapper;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\InvalidInput;
use Foresi\Shared\Infrastructure\Operation\OperationDefinition;
use Foresi\Shared\Infrastructure\Operation\OperationErrors;
use Foresi\Shared\Infrastructure\Operation\OperationRunner;
use Foresi\Shared\Infrastructure\Operation\Operations;
use Foresi\Shared\Infrastructure\Operation\OperationTool;
use Foresi\Tests\Shared\Infrastructure\Mcp\FakeScopes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The declaration of a write (ADR 0032): what is read from the input class,
 * how a client's fields become the input, the schema both surfaces publish,
 * and how a refusal is answered.
 */
final class OperationTest extends TestCase
{
    public function testTheFieldsAreReadFromTheConstructor(): void
    {
        $definition = OperationDefinition::of(new ThingHandler());

        self::assertSame('api_v1_operation_things.change', $definition->route());
        self::assertSame(['thing_id', 'name', 'note', 'colour', 'tags', 'count', 'shiny'], array_map(static fn (\Foresi\Shared\Infrastructure\Operation\OperationField $field): string => $field->wire, $definition->fields));
        self::assertSame(['name', 'note', 'colour', 'tags', 'count', 'shiny'], array_map(static fn (\Foresi\Shared\Infrastructure\Operation\OperationField $field): string => $field->wire, $definition->bodyFields()));
        self::assertTrue($definition->fields[0]->inPath);
        self::assertTrue($definition->fields[1]->required);
        self::assertFalse($definition->fields[2]->required);
        self::assertTrue($definition->fields[2]->nullable);
    }

    public function testAFieldLeftOutIsOmittedAndNullClearsIt(): void
    {
        $definition = OperationDefinition::of(new ThingHandler());

        $left = InputMapper::map($definition, ['thing_id' => 't', 'name' => 'Lamp']);
        $cleared = InputMapper::map($definition, ['thing_id' => 't', 'name' => 'Lamp', 'note' => null, 'tags' => ['a'], 'count' => 3, 'shiny' => true, 'colour' => 'red']);

        self::assertInstanceOf(ThingInput::class, $left);
        self::assertTrue(Omitted::is($left->note));
        self::assertTrue(Omitted::is($left->tags));
        self::assertNull($left->colour);
        self::assertInstanceOf(ThingInput::class, $cleared);
        self::assertNull($cleared->note);
        self::assertSame(['a'], $cleared->tags);
        self::assertSame(3, $cleared->count);
        self::assertTrue($cleared->shiny);
    }

    /**
     * @param array<string, mixed> $sent
     */
    #[DataProvider('badInputs')]
    public function testABadFieldIsRefusedByItsName(array $sent, string $field, string $message): void
    {
        try {
            InputMapper::map(OperationDefinition::of(new ThingHandler()), $sent + ['thing_id' => 't', 'name' => 'Lamp']);
            self::fail('The input was taken.');
        } catch (InvalidInput $invalid) {
            self::assertSame($field, $invalid->wire);
            self::assertSame($message, $invalid->getMessage());
            self::assertSame(RefusalKind::Invalid, $invalid->kind());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function badInputs(): iterable
    {
        yield 'unknown' => [['nmae' => 'x'], 'nmae', 'The field "nmae" is not a field of this operation.'];
        yield 'null where none' => [['name' => null], 'name', 'The field "name" must not be null.'];
        yield 'number for text' => [['name' => 5], 'name', 'The field "name" must be a string.'];
        yield 'text for number' => [['count' => '3'], 'count', 'The field "count" must be a whole number.'];
        yield 'text for flag' => [['shiny' => 'yes'], 'shiny', 'The field "shiny" must be true or false.'];
        yield 'not a list' => [['tags' => ['x' => 'a']], 'tags', 'The field "tags" must be a list of strings.'];
        yield 'other value' => [['colour' => 'green'], 'colour', 'The field "colour" must be one of red, blue.'];
        yield 'other item' => [['tags' => ['c']], 'tags', 'The field "tags" must be one of a, b.'];
    }

    public function testARequiredFieldIsRequired(): void
    {
        $this->expectExceptionObject(new InvalidInput('name', 'is required.'));

        InputMapper::map(OperationDefinition::of(new ThingHandler()), ['thing_id' => 't']);
    }

    public function testTheSchemaSaysWhatTheFieldsTake(): void
    {
        $schema = InputSchema::of(OperationDefinition::of(new ThingHandler())->fields);

        self::assertSame(['thing_id', 'name'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        $properties = $schema['properties'];
        self::assertIsArray($properties);
        self::assertSame(['type' => 'string', 'maxLength' => 10, 'description' => 'Its name.'], $properties['name']);
        self::assertSame(['type' => ['string', 'null'], 'description' => 'A note; null clears it.'], $properties['note']);
        self::assertSame(['type' => ['string', 'null'], 'enum' => ['red', 'blue', null], 'description' => 'Its colour.'], $properties['colour']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', 'b']], 'description' => 'Its tags.'], $properties['tags']);
        self::assertSame(['type' => 'integer', 'description' => 'How many.'], $properties['count']);
        self::assertSame(['type' => 'boolean', 'description' => 'Whether it shines.'], $properties['shiny']);
        self::assertSame(['type' => 'string', 'format' => 'uuid', 'description' => 'The id of the thing.'], $properties['thing_id']);
    }

    public function testTheToolTakesTheDeclarationAndRefusesInItsWords(): void
    {
        $scopes = new FakeScopes();
        $tool = new OperationTool(OperationDefinition::of(new ThingHandler(new ThingRefused(RefusalKind::Conflict, 'duplicate'))), new OperationRunner($scopes));

        self::assertSame('foresi_change_thing', $tool->name());
        self::assertSame('things:write', $tool->scope());
        self::assertFalse($tool->isDestructive());
        try {
            $tool->call(['thing_id' => 't', 'name' => 'Lamp']);
            self::fail('Nothing was refused.');
        } catch (ToolRefusal $refusal) {
            self::assertSame('duplicate', $refusal->errorCode);
            self::assertSame(['existing_ids' => ['x']], $refusal->details);
        }
        self::assertSame(['things:write'], $scopes->asked);

        try {
            $tool->call(['thing_id' => 't']);
            self::fail('Nothing was refused.');
        } catch (ToolRefusal $refusal) {
            self::assertSame('invalid_argument', $refusal->errorCode);
            self::assertSame(['parameter' => 'name', 'reason' => 'invalid_input'], $refusal->details);
        }
    }

    public function testAnOperationThatNeedsAConfirmationIsRefusedBeforeItRuns(): void
    {
        $handler = new ConfirmedThingHandler();
        $tool = new OperationTool(OperationDefinition::of($handler), new OperationRunner(new FakeScopes()));
        self::assertTrue($tool->isDestructive());

        try {
            new OperationRunner(new FakeScopes())->run(OperationDefinition::of($handler), ['thing_id' => 't']);
            self::fail('It ran.');
        } catch (ConfirmationRequired $refused) {
            self::assertSame('confirmation_required', $refused->reason());
            self::assertSame(RefusalKind::Forbidden, $refused->kind());
        }
        try {
            $tool->call(['thing_id' => 't']);
            self::fail('It ran.');
        } catch (ToolRefusal $refusal) {
            self::assertSame('confirmation_required', $refusal->errorCode);
        }
        self::assertFalse($handler->ran);
    }

    public function testARefusalIsAProblemOfItsKind(): void
    {
        $invalid = $this->problem(new ThingRefused(RefusalKind::Invalid, 'name_too_long', 'displayName'));
        self::assertSame([400, 'invalid_parameter', 'display_name', 'name_too_long'], [$invalid['status'], $invalid['code'], $invalid['parameter'], $invalid['reason']]);

        $duplicate = $this->problem(new ThingRefused(RefusalKind::Conflict, 'duplicate'));
        self::assertSame([409, 'duplicate', ['x']], [$duplicate['status'], $duplicate['code'], $duplicate['existing_ids']]);

        $confirm = $this->problem(new ConfirmationRequired('things.change'));
        self::assertSame([403, 'confirmation_required'], [$confirm['status'], $confirm['code']]);
    }

    public function testNamesToolsAndRoutesAreUnique(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Two operations are declared as things.change.');

        new Operations([new ThingHandler(), new ThingHandler()])->all();
    }

    /**
     * @return array<mixed>
     */
    private function problem(\Throwable $refused): array
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), Request::create('/api/v1/things/t', 'PATCH'), HttpKernelInterface::MAIN_REQUEST, $refused);
        new OperationErrors()->onException($event);
        $body = json_decode((string) $event->getResponse()?->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
