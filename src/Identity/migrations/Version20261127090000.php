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
 * A person's appearance on their profile: the size of the text and the density of the rows,
 * the same on every device. Everyone starts on today's sizes.
 */
final class Version20261127090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the text size and the density of a user\'s appearance';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('app_user');
        $users->addColumn('text_size', Types::STRING, ['length' => 16, 'default' => 'standard']);
        $users->addColumn('density', Types::STRING, ['length' => 16, 'default' => 'normal']);
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('app_user');
        $users->dropColumn('text_size');
        $users->dropColumn('density');
    }
}
