<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\AiReader\Unit;

use Foresi\AiReader\Application\ExtractionSchema;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use PHPUnit\Framework\TestCase;

final class ExtractionSchemaTest extends TestCase
{
    public function testTheSchemaIsTheOneTheModelIsToldAbout(): void
    {
        // Every word reaches the model, so a change to the schema shows up here and in review.
        self::assertJsonStringEqualsJsonFile(__DIR__.'/extraction-schema.json', (string) json_encode(ExtractionSchema::fields()));
    }

    public function testKindsAreTheKindsOfAReceivedDocument(): void
    {
        self::assertSame(
            json_encode(array_map(static fn (ReceivedDocumentKind $kind): string => $kind->value, ReceivedDocumentKind::cases())),
            json_encode(ExtractionSchema::KINDS),
        );
    }

    public function testEveryPropertyIsRequiredAndNullable(): void
    {
        $schema = ExtractionSchema::fields();
        $properties = $this->at($schema, 'properties');

        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(ExtractionSchema::FIELDS, array_keys($properties));
        self::assertSame(ExtractionSchema::FIELDS, $schema['required']);
        foreach ($properties as $name => $property) {
            self::assertIsArray($property);
            self::assertContains(['type' => 'null'], $this->at($property, 'anyOf'), $name.' can be null');
        }
        self::assertContains(['type' => 'string', 'enum' => ExtractionSchema::KINDS], $this->at($this->at($properties, 'kind'), 'anyOf'));
        self::assertContains(['type' => 'string', 'format' => 'date'], $this->at($this->at($properties, 'issue_date'), 'anyOf'));
    }

    public function testEveryPropertyTellsTheModelWhatToPut(): void
    {
        $properties = $this->at(ExtractionSchema::fields(), 'properties');
        foreach ($properties as $name => $property) {
            self::assertIsArray($property);
            self::assertIsString($property['description'] ?? null, $name.' has a description');
            self::assertNotSame('', trim($property['description']));
        }
        $rows = $this->at($this->at($this->at($this->at($properties, 'vat_recap'), 'anyOf'), 0), 'items');
        foreach ($this->at($rows, 'properties') as $name => $property) {
            self::assertIsArray($property);
            self::assertIsString($property['description'] ?? null, 'vat_recap.'.$name.' has a description');
        }
        self::assertStringContainsString('including VAT', $this->describe($properties, 'total'));
        self::assertStringContainsString('not the one it is addressed to', $this->describe($properties, 'supplier_name'));
        self::assertStringContainsString('ISO 3166-1', $this->describe($properties, 'supplier_country'));
        self::assertStringContainsString('ISO 4217', $this->describe($properties, 'currency'));
        self::assertStringContainsString('YYYY-MM-DD', $this->describe($properties, 'issue_date'));
    }

    public function testNoObjectTakesAnotherPropertyAndNoConstraintIsUsedThatTheServiceRefuses(): void
    {
        $schema = ExtractionSchema::fields();
        $properties = $this->at($schema, 'properties');
        $recap = $this->at($this->at($this->at($properties, 'vat_recap'), 'anyOf'), 0);
        $items = $this->at($recap, 'items');

        self::assertSame('array', $recap['type']);
        self::assertSame(['rate', 'base', 'vat'], $items['required']);
        self::assertFalse($items['additionalProperties']);
        self::assertSame(['rate', 'base', 'vat'], array_keys($this->at($items, 'properties')));
        $json = json_encode($schema, \JSON_THROW_ON_ERROR);
        foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems'] as $constraint) {
            self::assertStringNotContainsString('"'.$constraint.'"', $json);
        }
        self::assertArrayNotHasKey('lines', $properties);
    }

    /**
     * @param array<array-key, mixed> $from
     *
     * @return array<array-key, mixed>
     */
    private function at(array $from, string|int $key): array
    {
        self::assertArrayHasKey($key, $from);
        $value = $from[$key];
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $properties
     */
    private function describe(array $properties, string $name): string
    {
        $description = $this->at($properties, $name)['description'] ?? null;
        self::assertIsString($description);

        return $description;
    }
}
