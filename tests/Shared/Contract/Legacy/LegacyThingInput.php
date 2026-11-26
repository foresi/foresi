<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Contract\Legacy;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Api\Operation;

/**
 * An input written against the names the contract had before module API 1.0,
 * as a module outside this repository may still have it. Loaded only by
 * DeprecatedAliasesTest, in a process of its own.
 */
#[Operation(name: 'legacy_things.change', method: 'PATCH', path: '/api/v1/legacy-things/{thingId}', tool: 'foresi_change_legacy_thing', scope: 'things:write', title: 'Change a legacy thing', description: 'Changes a legacy thing.')] // @phpstan-ignore attribute.notFound (the deprecated alias, which static analysis cannot see)
final readonly class LegacyThingInput
{
    public function __construct(
        #[Field('The id of the thing.', format: 'uuid')] // @phpstan-ignore attribute.notFound (the deprecated alias, which static analysis cannot see)
        public string $thingId,
        #[Field('A note; null clears it.', maxLength: 10)] // @phpstan-ignore attribute.notFound, class.notFound, class.notFound (the deprecated alias, which static analysis cannot see)
        public string|Omitted|null $note = new Omitted(), // @phpstan-ignore class.notFound (the deprecated alias, which static analysis cannot see)
    ) {
    }
}
