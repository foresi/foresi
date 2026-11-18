<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118300001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the display each person last used on a saved view';
    }

    public function up(Schema $schema): void
    {
        $states = $schema->createTable('saved_view_user_state');
        $states->addColumn('view_id', Types::GUID);
        $states->addColumn('user_id', Types::GUID);
        $states->addColumn('display', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $states->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $states->setPrimaryKey(['view_id', 'user_id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('saved_view_user_state');
    }
}
