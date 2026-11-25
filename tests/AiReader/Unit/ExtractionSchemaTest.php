<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\AiReader\Unit;

use Foresi\AiReader\Application\ExtractionSchema;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use PHPUnit\Framework\TestCase;

final class ExtractionSchemaTest extends TestCase
{
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
}
