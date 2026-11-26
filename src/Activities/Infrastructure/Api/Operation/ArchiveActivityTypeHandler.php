<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Api\Operation;

use Foresi\Activities\Application\ActivityTypeWrites;
use Foresi\Activities\Application\Api\ArchiveActivityTypeInput;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Activities\Infrastructure\Api\ActivitiesRoutes;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ArchiveActivityTypeHandler implements OperationHandler
{
    public function __construct(private ActivityTypeWrites $writes, private ActivitiesJson $json, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return ArchiveActivityTypeInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ArchiveActivityTypeInput);
        $type = $this->writes->archive($input);

        return new OperationOutput($this->json->type($type), 'Archived the activity type.', $this->urls->generate(ActivitiesRoutes::TYPES, []));
    }
}
