<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Field;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Foresi\Shared\Application\Field\FieldRecord;
use Foresi\Shared\Application\Field\FieldRecords;
use Foresi\Shared\Contract\Field\CustomField;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldExpression;
use Foresi\Shared\Contract\Field\FieldSource;
use Foresi\Shared\Contract\Field\RecordExtension;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The custom fields the modules declare on their record extensions (ADR 0045): every
 * Doctrine entity that implements a record's extension interface, each of its
 * properties with CustomField a field. The definitions are checked once and kept in
 * the system cache, apart from debug, where a change of the code shows at once.
 *
 * An extension's row exists only while it holds a value: writing the last value away
 * removes it. Nothing is flushed here; the caller's transaction does.
 *
 * @phpstan-type Catalog array<string, array{definitions: list<FieldDefinition>, fields: array<string, array{class: class-string, property: string, id: string, length: ?int, type: string}>}>
 */
final class DoctrineFieldSource implements FieldSource
{
    private const string CACHE_KEY = 'foresi.shared.custom_fields';

    /** @var Catalog|null */
    private ?array $catalog = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FieldRecords $records,
        private readonly ModuleGraph $modules,
        #[Autowire(service: 'cache.system')]
        private readonly CacheInterface $cache,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug = false,
    ) {
    }

    public function definitions(string $record): array
    {
        return $this->catalog()[$record]['definitions'] ?? [];
    }

    public function read(string $record, array $ids): array
    {
        $ids = array_values(array_unique(Id::onlyUuids($ids)));
        $byClass = [];
        foreach ($this->catalog()[$record]['fields'] ?? [] as $key => $field) {
            $byClass[$field['class']][$key] = $field;
        }
        if ([] === $ids || [] === $byClass) {
            return [];
        }
        $values = [];
        foreach ($byClass as $class => $fields) {
            $id = reset($fields)['id'];
            $select = implode(', ', array_map(static fn (array $field): string => 'e.'.$field['property'], $fields));
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->em->createQuery('SELECT e.'.$id.', '.$select.' FROM '.$class.' e WHERE e.'.$id.' IN (:ids)')
                ->setParameter('ids', $ids)
                ->getResult(Query::HYDRATE_ARRAY);
            foreach ($rows as $row) {
                $recordId = $row[$id];
                \assert(\is_string($recordId));
                foreach ($fields as $key => $field) {
                    $value = $row[$field['property']] ?? null;
                    if (null !== $value && [] !== $value) {
                        $values[$recordId][$key] = $value;
                    }
                }
            }
        }

        return $values;
    }

    public function write(string $record, string $id, array $values): void
    {
        $fields = $this->catalog()[$record]['fields'] ?? [];
        $byClass = [];
        foreach ($values as $key => $value) {
            $field = $fields[$key] ?? throw new \InvalidArgumentException(\sprintf('The %s has no custom field "%s".', $record, $key));
            $byClass[$field['class']][$field['property']] = $this->stored($key, $field, $value);
        }
        foreach ($byClass as $class => $changes) {
            $metadata = $this->em->getClassMetadata($class);
            $entity = $this->em->find($class, $id);
            if (null === $entity) {
                if ([] === array_filter($changes, static fn (mixed $value): bool => null !== $value)) {
                    continue;
                }
                $entity = $metadata->newInstance();
                $metadata->setFieldValue($entity, $metadata->getSingleIdentifierFieldName(), $id);
                $this->em->persist($entity);
            }
            foreach ($changes as $property => $value) {
                $metadata->setFieldValue($entity, $property, $value);
            }
            if ($this->empty($metadata, $entity)) {
                $this->em->remove($entity);
            }
        }
    }

    public function expression(string $record, string $key, string $root): FieldExpression
    {
        $alias = 'cf_'.$key;
        $field = $this->catalog()[$record]['fields'][$key] ?? throw new \InvalidArgumentException(\sprintf('The %s has no custom field "%s".', $record, $key));
        $entity = $this->records->get($record)?->entity() ?? throw new \LogicException(\sprintf('No module declares the record kind "%s".', $record));
        $rootId = $this->em->getClassMetadata($entity)->getSingleIdentifierFieldName();

        return new FieldExpression($field['class'], $alias, $alias.'.'.$field['id'].' = '.$root.'.'.$rootId, $alias.'.'.$field['property']);
    }

    /**
     * @param array{class: class-string, property: string, id: string, length: ?int, type: string} $field
     */
    private function stored(string $key, array $field, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        return match ($field['type']) {
            'integer', 'smallint', 'bigint' => \is_int($value) || (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) ? (int) $value : throw new \InvalidArgumentException(\sprintf('The field "%s" takes a whole number.', $key)),
            'decimal' => \is_int($value) || \is_float($value) || is_numeric($value) ? (string) $value : throw new \InvalidArgumentException(\sprintf('The field "%s" takes a number.', $key)),
            'float' => \is_int($value) || \is_float($value) || is_numeric($value) ? (float) $value : throw new \InvalidArgumentException(\sprintf('The field "%s" takes a number.', $key)),
            'string' => \is_string($value) && (null === $field['length'] || mb_strlen($value) <= $field['length']) ? $value : throw new \InvalidArgumentException(\sprintf('The field "%s" takes a text of at most %d characters.', $key, $field['length'] ?? 255)),
            default => $value,
        };
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function empty(ClassMetadata $metadata, object $entity): bool
    {
        return array_all($metadata->getFieldNames(), fn (string $name): bool => $metadata->isIdentifier($name) || null === $metadata->getFieldValue($entity, $name));
    }

    /**
     * @return Catalog
     */
    private function catalog(): array
    {
        if (null !== $this->catalog) {
            return $this->catalog;
        }
        if ($this->debug) {
            return $this->catalog = $this->scan();
        }
        /** @var Catalog $catalog */
        $catalog = $this->cache->get(self::CACHE_KEY, fn (): array => $this->scan());

        return $this->catalog = $catalog;
    }

    /**
     * @return Catalog
     */
    private function scan(): array
    {
        $catalog = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass || !is_a($metadata->getName(), RecordExtension::class, true)) {
                continue;
            }
            $record = $this->recordOf($metadata->getName());
            foreach ($this->fieldsOf($metadata, $record) as $key => [$definition, $field]) {
                if (isset($catalog[$record->record()]['fields'][$key])) {
                    throw new \LogicException(\sprintf('Two custom fields of the %s have the key "%s"; the second is %s::$%s.', $record->record(), $key, $metadata->getName(), $field['property']));
                }
                $catalog[$record->record()]['definitions'][] = $definition;
                $catalog[$record->record()]['fields'][$key] = $field;
            }
        }

        return $catalog;
    }

    private function recordOf(string $class): FieldRecord
    {
        $found = array_values(array_filter($this->records->all(), static fn (FieldRecord $record): bool => is_a($class, $record->extension(), true)));

        return match (\count($found)) {
            1 => $found[0],
            0 => throw new \LogicException(\sprintf('%s implements RecordExtension but none of the extension interfaces of the records, such as Foresi\Party\Contract\Field\ContactExtension.', $class)),
            default => throw new \LogicException(\sprintf('%s extends more than one record; an extension adds fields to one record.', $class)),
        };
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, array{FieldDefinition, array{class: class-string, property: string, id: string, length: ?int, type: string}}>
     */
    private function fieldsOf(ClassMetadata $metadata, FieldRecord $record): array
    {
        $class = $metadata->getName();
        $module = $this->moduleOf($class);
        $identifier = $metadata->getIdentifierFieldNames();
        if (1 !== \count($identifier) || 'guid' !== $metadata->getTypeOfField($identifier[0])) {
            throw new \LogicException(\sprintf('%s has the id of the %s as its one id, a guid column.', $class, $record->record()));
        }
        $id = $identifier[0];
        if ([] !== $metadata->getAssociationNames()) {
            throw new \LogicException(\sprintf('%s holds custom fields only, no associations; a reference is a contact or a user field.', $class));
        }
        $declared = [];
        foreach ($metadata->getReflectionClass()->getProperties() as $property) {
            $attribute = $property->getAttributes(CustomField::class)[0] ?? null;
            if (null !== $attribute) {
                $declared[$property->getName()] = $attribute->newInstance();
            }
        }
        foreach ($metadata->getFieldNames() as $name) {
            if ($name !== $id && !isset($declared[$name])) {
                throw new \LogicException(\sprintf('%s::$%s is mapped but no custom field; every column of an extension other than its id is a field with #[CustomField].', $class, $name));
            }
        }
        $prefix = str_replace('-', '_', $module->id->value);
        $fields = [];
        foreach ($declared as $property => $field) {
            $where = $class.'::$'.$property;
            if (!$metadata->hasField($property) || $property === $id) {
                throw new \LogicException(\sprintf('%s has #[CustomField] but is no mapped column of its own.', $where));
            }
            $type = (string) $metadata->getTypeOfField($property);
            if (!\in_array($type, $field->kind->columnTypes(), true)) {
                throw new \LogicException(\sprintf('%s is a %s field, a column of type %s, not %s.', $where, $field->kind->value, implode(' or ', $field->kind->columnTypes()), $type));
            }
            if (!$metadata->isNullable($property)) {
                throw new \LogicException(\sprintf('%s is a nullable column: a record without the value has none.', $where));
            }
            if ('' === $field->label) {
                throw new \LogicException(\sprintf('%s needs a label, a translation key.', $where));
            }
            if ($field->kind->selects() !== ([] !== $field->options)) {
                throw new \LogicException(\sprintf('%s: a select and a multi-select list their options, and only they do.', $where));
            }
            foreach ($field->options as $option => $label) {
                if (1 !== preg_match(CustomField::OPTION, (string) $option) || '' === $label) {
                    throw new \LogicException(\sprintf('%s: an option has a key of lowercase letters, digits and underscores, and a label, not "%s".', $where, $option));
                }
            }
            if ($field->sortable && !$field->kind->sortable()) {
                throw new \LogicException(\sprintf('%s: a %s field does not sort.', $where, $field->kind->value));
            }
            if ($field->searchable && !$field->kind->searchable()) {
                throw new \LogicException(\sprintf('%s: only a text, a long text or a URL is searchable.', $where));
            }
            $key = $prefix.'_'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
            $options = [];
            foreach ($field->options as $option => $label) {
                $options[(string) $option] = $label;
            }
            $fields[$key] = [
                new FieldDefinition($key, $record->record(), $field->kind, $field->label, $options, $module->id->value, $field->filterable, $field->sortable, $field->required, $field->guests, $field->api, $field->template, $field->import, $field->searchable),
                ['class' => $class, 'property' => $property, 'id' => $id, 'length' => 'string' === $type ? ($metadata->getFieldMapping($property)->length ?? 255) : null, 'type' => $type],
            ];
        }

        return $fields;
    }

    /**
     * The installed module a class belongs to: the one with the longest namespace that holds it.
     */
    private function moduleOf(string $class): ModuleManifest
    {
        $owner = null;
        foreach ($this->modules->all() as $module) {
            if ($module->owns($class) && (null === $owner || \strlen($module->namespace) > \strlen($owner->namespace))) {
                $owner = $module;
            }
        }

        return $owner ?? throw new \LogicException(\sprintf('%s belongs to no installed module, so its custom fields have no key.', $class));
    }
}
