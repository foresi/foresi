<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A person's own photo: the file lies in the share directory, the user keeps
 * the version that its address carries.
 */
final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the version of a user\'s photo';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('app_user')->addColumn('photo_version', Types::STRING, ['length' => 32, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('app_user')->dropColumn('photo_version');
    }
}
