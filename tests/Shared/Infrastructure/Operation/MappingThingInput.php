<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A test input with one field that is a single JSON object (Field::ONE_OBJECT), such as a mapping of kind and shares.
 */
#[Operation(name: 'things.map', method: 'PUT', path: '/api/v1/things/{thingId}/mapping', tool: 'foresi_map_thing', scope: 'things:write', title: 'Map a thing', description: 'Maps a thing.', response: 'Thing')]
final readonly class MappingThingInput
{
    /**
     * @param array<mixed>|null $mapping
     */
    public function __construct(
        #[Field('The id of the thing.', format: 'uuid')]
        public string $thingId,
        #[Field('The mapping as an object; null clears it.', items: Field::ONE_OBJECT)]
        public ?array $mapping,
    ) {
    }
}
