<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Api\Operation;

use Foresi\Activities\Application\ActivityTypeWrites;
use Foresi\Activities\Application\Api\UpdateActivityTypeInput;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Activities\Infrastructure\Api\ActivitiesRoutes;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class UpdateActivityTypeHandler implements OperationHandler
{
    public function __construct(private ActivityTypeWrites $writes, private ActivitiesJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return UpdateActivityTypeInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateActivityTypeInput);
        $type = $this->writes->update($input);

        return new OperationOutput($this->json->type($type), 'Changed the activity type.', $this->urls->generate(ActivitiesRoutes::TYPES, []));
    }
}
