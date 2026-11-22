<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Api\Operation;

use Foresi\Activities\Application\ActivityWrites;
use Foresi\Activities\Application\Api\UpdateActivityInput;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Activities\Infrastructure\Api\ActivitiesRoutes;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class UpdateActivityHandler implements OperationHandler
{
    public function __construct(private ActivityWrites $writes, private ActivitiesJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return UpdateActivityInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateActivityInput);
        $activity = $this->writes->update($input);

        return new OperationOutput($this->json->activity($activity), 'Changed the activity.', $this->urls->generate(ActivitiesRoutes::ACTIVITY, ['activityId' => $activity->id()]));
    }
}
