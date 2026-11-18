<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The modules move from the namespace App\ to Foresi\ (ADR 0034). The audit log
 * stores the class of each entity and of its root, so the stored names move too.
 *
 * Messenger keeps serialized messages with class names in messenger_messages.
 * They are not rewritten here: drain the queues before this deploy. A message
 * left behind cannot be decoded and lands in the failed transport.
 */
final class Version20261118100001 extends AbstractMigration
{
    private const array COLUMNS = ['entity_class', 'root_class'];

    public function getDescription(): string
    {
        return 'Rename the class names stored in the audit log from App\ to Foresi\\';
    }

    public function up(Schema $schema): void
    {
        $this->rename('App\\', 'Foresi\\');
    }

    public function down(Schema $schema): void
    {
        $this->rename('Foresi\\', 'App\\');
    }

    private function rename(string $from, string $to): void
    {
        $length = \strlen($from);
        foreach (self::COLUMNS as $column) {
            $this->addSql(
                \sprintf('UPDATE audit_entry SET %1$s = CAST(? AS VARCHAR(255)) || SUBSTR(%1$s, %2$d) WHERE SUBSTR(%1$s, 1, %3$d) = ?', $column, $length + 1, $length),
                [$to, $from],
            );
        }
    }
}
