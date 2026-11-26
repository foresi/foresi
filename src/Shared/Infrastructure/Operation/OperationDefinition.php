<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Foresi\Shared\Application\Field\CustomFieldsOf;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A declared operation as the surfaces use it: the declaration, the input class
 * and its fields, and the handler that runs it.
 */
#[Exclude]
final readonly class OperationDefinition
{
    public const string ROUTE_PREFIX = 'api_v1_operation_';
    public const array METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param class-string         $input
     * @param list<OperationField> $fields
     */
    private function __construct(
        public Operation $operation,
        public string $input,
        public array $fields,
        public OperationHandler $handler,
    ) {
    }

    /**
     * @throws \LogicException when the input is not declared as an operation can be
     */
    public static function of(OperationHandler $handler): self
    {
        $input = $handler::input();
        $class = new \ReflectionClass($input);
        // IS_INSTANCEOF, so that an input written against a deprecated alias of the attribute still counts.
        $attributes = $class->getAttributes(Operation::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ([] === $attributes) {
            throw new \LogicException(\sprintf('%s is the input of %s but carries no #[Operation].', $input, $handler::class));
        }
        $operation = $attributes[0]->newInstance();
        if (!\in_array($operation->method, self::METHODS, true) || !str_starts_with($operation->path, '/api/v1/')) {
            throw new \LogicException(\sprintf('The operation %s must be a POST, PUT, PATCH or DELETE below /api/v1/.', $operation->name));
        }
        preg_match_all('/\{([a-zA-Z]+)\}/', $operation->path, $matches);
        $inPath = $matches[1];
        $fields = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $fields[] = self::field($operation, $parameter, \in_array($parameter->getName(), $inPath, true));
        }
        $names = array_map(static fn (OperationField $field): string => $field->name, $fields);
        foreach ($inPath as $name) {
            if (!\in_array($name, $names, true)) {
                throw new \LogicException(\sprintf('The path of %s names {%s}, which its input has no field for.', $operation->name, $name));
            }
        }

        return new self($operation, $input, $fields, $handler);
    }

    public function route(): string
    {
        return self::ROUTE_PREFIX.$this->operation->name;
    }

    /**
     * @return list<OperationField>
     */
    public function bodyFields(): array
    {
        return array_values(array_filter($this->fields, static fn (OperationField $field): bool => !$field->inPath));
    }

    private static function field(Operation $operation, \ReflectionParameter $parameter, bool $inPath): OperationField
    {
        $type = $parameter->getType();
        $names = match (true) {
            $type instanceof \ReflectionNamedType => [$type->getName()],
            $type instanceof \ReflectionUnionType => array_map(static fn (\ReflectionType $member): string => $member instanceof \ReflectionNamedType ? $member->getName() : 'mixed', $type->getTypes()),
            default => ['mixed'],
        };
        if (true === $type?->allowsNull() && !\in_array('null', $names, true)) {
            $names[] = 'null';
        }
        // A type written against the deprecated alias of Omitted is the same class under another name.
        $names = array_map(static fn (string $name): string => 'null' !== $name && !\in_array($name, OperationField::TYPES, true) && is_a($name, Omitted::class, true) ? Omitted::class : $name, $names);
        $base = array_values(array_diff($names, ['null', Omitted::class]));
        if (1 !== \count($base) || !\in_array($base[0], OperationField::TYPES, true)) {
            throw new \LogicException(\sprintf('The field %s of %s must be a string, int, bool or array, optionally null or Omitted.', $parameter->getName(), $operation->name));
        }
        $describe = ($parameter->getAttributes(Field::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();
        if ('array' === $base[0] && null === $describe?->items) {
            throw new \LogicException(\sprintf('The list %s of %s needs #[Field(items: ...)].', $parameter->getName(), $operation->name));
        }

        return new OperationField(
            $parameter->getName(),
            OperationField::wireName($parameter->getName()),
            $base[0],
            \in_array('null', $names, true),
            !$parameter->isDefaultValueAvailable(),
            $inPath,
            $describe,
            ($parameter->getAttributes(CustomFieldsOf::class)[0] ?? null)?->newInstance()->record,
        );
    }
}
