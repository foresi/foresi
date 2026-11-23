<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A line keeps its id when the lines are reordered or an earlier one goes, so
 * two lines swap positions in one save. The unique index on the position would
 * refuse the swap while the first line moves; the document's list gives the
 * positions, so the index only serves the reading order.
 */
final class Version20261124090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let lines of received documents swap positions: the position index is no longer unique';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_received_document_line_position');
        $this->addSql('DROP INDEX idx_received_document_line_document');
        $this->addSql('CREATE INDEX idx_received_document_line_document ON received_document_line (document_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_received_document_line_document');
        $this->addSql('CREATE INDEX idx_received_document_line_document ON received_document_line (document_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_received_document_line_position ON received_document_line (document_id, position)');
    }
}
