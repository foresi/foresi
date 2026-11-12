<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Dbal;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds the event tables to the schema the ORM generates, as the Messenger
 * transport does for its own table, so the schema tool creates them in tests
 * and the schema validation finds them in the database.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class EventLogSchema
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        EventLogTable::define($args->getSchema());
    }
}
