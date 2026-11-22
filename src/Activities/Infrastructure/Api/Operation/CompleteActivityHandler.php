<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Api\Operation;

use Foresi\Activities\Application\ActivityWrites;
use Foresi\Activities\Application\Api\CompleteActivityInput;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Activities\Infrastructure\Api\ActivitiesRoutes;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The activity done, with the next one it planned under "next" (null when none).
 */
final readonly class CompleteActivityHandler implements OperationHandler
{
    public function __construct(private ActivityWrites $writes, private ActivitiesJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return CompleteActivityInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CompleteActivityInput);
        [$activity, $next] = $this->writes->complete($input);

        return new OperationOutput(
            [...$this->json->activity($activity), 'next' => $next instanceof Activity ? $this->json->activity($next) : null],
            $next instanceof Activity ? 'Marked the activity done and planned the next one.' : 'Marked the activity done.',
            $this->urls->generate(ActivitiesRoutes::ACTIVITY, ['activityId' => $activity->id()]),
        );
    }
}
