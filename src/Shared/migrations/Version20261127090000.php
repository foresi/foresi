<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A list keeps no density of its own any more: how tall the rows are is the person's density on
 * their profile. The key leaves the displays of the saved views and the displays a person's lists
 * remembered; a view whose display held nothing else goes back to the list's defaults, and a
 * remembered display left empty is forgotten. Going down brings nothing back: every list then
 * shows the person's density.
 */
final class Version20261127090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the density from the displays of saved views and of the views a person remembers';
    }

    public function up(Schema $schema): void
    {
        // The rows change in postUp(), read and written in PHP, the same on SQLite and PostgreSQL.
    }

    public function postUp(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative("SELECT id, display FROM saved_view WHERE CAST(display AS TEXT) LIKE '%\"density\"%'") as $row) {
            $display = $this->withoutDensity($row['display']);
            if (null !== $display) {
                $this->connection->update('saved_view', ['display' => [] === $display ? null : json_encode($display, \JSON_THROW_ON_ERROR)], ['id' => $row['id']]);
            }
        }
        foreach ($this->connection->fetchAllAssociative("SELECT view_id, user_id, display FROM saved_view_user_state WHERE CAST(display AS TEXT) LIKE '%\"density\"%'") as $row) {
            $display = $this->withoutDensity($row['display']);
            $key = ['view_id' => $row['view_id'], 'user_id' => $row['user_id']];
            if ([] === $display) {
                $this->connection->delete('saved_view_user_state', $key);
            } elseif (null !== $display) {
                $this->connection->update('saved_view_user_state', ['display' => json_encode($display, \JSON_THROW_ON_ERROR)], $key);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Nothing comes back: every list shows the person's density.
    }

    /**
     * The display without its density; null when it had none (a column or a text that only
     * mentions the word).
     *
     * @return array<array-key, mixed>|null
     */
    private function withoutDensity(mixed $json): ?array
    {
        $display = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($display) || !\array_key_exists('density', $display)) {
            return null;
        }
        unset($display['density']);

        return $display;
    }
}
