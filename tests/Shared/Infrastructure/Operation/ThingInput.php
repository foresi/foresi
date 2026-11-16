<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Operation;

use App\Shared\Application\Api\Field;
use App\Shared\Application\Api\Omitted;
use App\Shared\Application\Api\Operation;

#[Operation(name: 'things.change', method: 'PATCH', path: '/api/v1/things/{thingId}', tool: 'foresi_change_thing', scope: 'things:write', title: 'Change a thing', description: 'Changes a thing.', response: 'Thing')]
final readonly class ThingInput
{
    /**
     * @param list<string>|Omitted $tags
     */
    public function __construct(
        #[Field('The id of the thing.', format: 'uuid')]
        public string $thingId,
        #[Field('Its name.', maxLength: 10)]
        public string $name,
        #[Field('A note; null clears it.')]
        public string|Omitted|null $note = new Omitted(),
        #[Field('Its colour.', enum: ['red', 'blue'])]
        public ?string $colour = null,
        #[Field('Its tags.', enum: ['a', 'b'], items: 'string')]
        public array|Omitted $tags = new Omitted(),
        #[Field('How many.')]
        public int $count = 1,
        #[Field('Whether it shines.')]
        public bool $shiny = false,
    ) {
    }
}
