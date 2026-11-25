<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Api\Operation;

use Foresi\Activities\Application\ActivityWrites;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Activities\Infrastructure\Api\ActivitiesRoutes;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class TrashActivityHandler implements OperationHandler
{
    public function __construct(private ActivityWrites $writes, private ActivitiesJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return TrashActivityInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof TrashActivityInput);
        $activity = $this->writes->trash($input);

        return new OperationOutput($this->json->activity($activity), 'Put the activity in the trash.', $this->urls->generate(ActivitiesRoutes::ACTIVITY, ['activityId' => $activity->id()]));
    }
}
