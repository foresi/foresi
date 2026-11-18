<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One instance holds one firm (ADR 0039): the migrations that follow fold the
 * per-firm settings into shared ones and would mix the data of several firms.
 * This one stops before them when the database holds more than one legal
 * entity, archived ones included. A fresh database has none yet and passes. It
 * changes nothing, so going down is free.
 */
final class Version20261118210001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stop unless the database holds at most one firm';
    }

    public function up(Schema $schema): void
    {
        $firms = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM legal_entity');
        $this->abortIf(
            $firms > 1,
            \sprintf('This database holds %d firms (legal entities, archived ones included). Foresi now runs one firm per instance (ADR 0039): move each firm but one into an instance of its own before upgrading.', $firms),
        );
        $this->write(\sprintf('Firms found: %d.', $firms));
    }

    public function down(Schema $schema): void
    {
    }
}
