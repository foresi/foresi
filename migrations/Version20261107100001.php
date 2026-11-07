<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The client and subcontractor roles become one external role: what an external
 * person is comes from the contact their membership links. Going down cannot
 * tell the two apart again, so every external membership becomes a client one.
 */
final class Version20261107100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace the client and subcontractor roles of memberships with external';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE membership SET role = 'external' WHERE role IN ('client', 'subcontractor')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE membership SET role = 'client' WHERE role = 'external'");
    }
}
