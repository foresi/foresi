<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * One active address per person and purpose: the unique index covers only
 * addresses that have an owner and are not revoked. On PostgreSQL the schema the
 * ORM generates carries that condition, so the schema validation matches the
 * migration. SQLite does not report the condition of an index it reads back, so
 * there the mapping stays a plain unique index and the migration writes the
 * condition itself (Version20261125100000::postUp()).
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class InboundAddressSchema
{
    public const string INDEX = 'uniq_mail_inbound_address_owner_purpose';
    public const string CONDITION = '((revoked_at IS NULL) AND (owner_user_id IS NOT NULL))';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        if (!$args->getEntityManager()->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }
        $schema = $args->getSchema();
        if (!$schema->hasTable('mail_inbound_address')) {
            return;
        }
        $table = $schema->getTable('mail_inbound_address');
        if (!$table->hasIndex(self::INDEX)) {
            return;
        }
        $columns = $table->getIndex(self::INDEX)->getColumns();
        $table->dropIndex(self::INDEX);
        $table->addUniqueIndex($columns, self::INDEX, ['where' => self::CONDITION]);
    }
}
