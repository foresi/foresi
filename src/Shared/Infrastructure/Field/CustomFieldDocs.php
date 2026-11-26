<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Field;

use Foresi\Shared\Application\Field\FieldRecords;
use Nelmio\ApiDocBundle\Describer\DescriberInterface;
use Nelmio\ApiDocBundle\OpenApiPhp\Util;
use OpenApi\Annotations\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The custom fields of each record kind in the OpenAPI document (ADR 0045): one
 * schema per kind, such as ContactCustomFields, generated from the definitions of
 * the installed modules, which the record's schemas and its create and update
 * bodies refer to.
 */
#[AutoconfigureTag('nelmio_api_doc.describer.default', ['priority' => -450])]
final readonly class CustomFieldDocs implements DescriberInterface
{
    public function __construct(private FieldRecords $records, private CustomFieldSchema $schema)
    {
    }

    public function describe(OpenApi $api): void
    {
        foreach (array_keys($this->records->all()) as $record) {
            $schema = $this->schema->of($record, false);
            if ($schema['properties'] instanceof \stdClass) {
                unset($schema['properties']);
            } elseif (\is_array($schema['properties'])) {
                $schema['properties'] = array_map(self::nullable(...), $schema['properties']);
            }
            Util::merge(Util::getSchema($api, CustomFieldSchema::component($record)), $schema, true);
        }
    }

    /**
     * A property whose type is a list with null, as OpenAPI 3.0 writes it: its one type and nullable.
     */
    private static function nullable(mixed $property): mixed
    {
        if (!\is_array($property) || !\is_array($property['type'] ?? null) || !\in_array('null', $property['type'], true)) {
            return $property;
        }
        $types = array_values(array_filter($property['type'], static fn (mixed $type): bool => 'null' !== $type));

        return ['type' => 1 === \count($types) ? $types[0] : $types, 'nullable' => true] + $property;
    }
}
