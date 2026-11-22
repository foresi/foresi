<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Foresi\Shared\Domain\Id;
use Symfony\Component\Clock\Clock;

/**
 * The VAT payer statuses of the owner profile become the VAT statuses of the
 * firm, which Taxes and contributions reads from then on. "yes" is a payer by
 * the month and "no" not registered, each from its day; the firm keeps the
 * status of today, and the profile loses its own. Without such statuses it
 * changes nothing, so the firm's status from the start stays.
 */
final class Version20261122210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the VAT payer statuses of the owner profile to the VAT statuses of the firm';
    }

    public function up(Schema $schema): void
    {
        // The data moves in postUp(), once the firm's table exists: nothing is read while migrations are planned.
    }

    public function postUp(Schema $schema): void
    {
        /** @var list<array{value: string, valid_from: string}> $rows */
        $rows = $this->connection->fetchAllAssociative("SELECT value, valid_from FROM taxes_status_period WHERE kind = 'vat_payer' ORDER BY valid_from");
        if ([] === $rows) {
            return;
        }

        $this->connection->executeStatement('DELETE FROM party_vat_status_period');
        foreach ($rows as $row) {
            $payer = 'yes' === $row['value'];
            $this->connection->insert('party_vat_status_period', [
                'id' => Id::generate(),
                'status' => $payer ? 'registered' : 'not_registered',
                'period_length' => $payer ? 'month' : null,
                'valid_from' => substr($row['valid_from'], 0, 10),
            ]);
        }

        /** @var array{id: string, address_country_code: string}|false $firm */
        $firm = $this->connection->fetchAssociative('SELECT id, address_country_code FROM legal_entity ORDER BY id LIMIT 1');
        if (false !== $firm) {
            $this->connection->update('legal_entity', ['vat_status' => self::holding($rows, $this->today($firm['address_country_code']))], ['id' => $firm['id']]);
        }

        $this->connection->executeStatement("DELETE FROM taxes_status_period WHERE kind = 'vat_payer'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The VAT payer statuses now live on the firm; change them there.');
    }

    /**
     * The status of the stretch that holds on the day; before the first one
     * begins, that first one, as the firm's daily refresh keeps it.
     *
     * @param non-empty-list<array{value: string, valid_from: string}> $rows oldest first
     */
    public static function holding(array $rows, string $day): string
    {
        $holding = $rows[0];
        foreach ($rows as $row) {
            if (substr($row['valid_from'], 0, 10) <= $day) {
                $holding = $row;
            }
        }

        return 'yes' === $holding['value'] ? 'registered' : 'not_registered';
    }

    /**
     * Today in the instance's zone: APP_TIMEZONE when it names a zone, else the
     * first zone of the firm's country, else UTC. A migration has no InstanceClock, so it
     * reads the same sources; the firm's daily refresh corrects a near miss.
     */
    private function today(string $country): string
    {
        $configured = $_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE');
        $zone = new \DateTimeZone('UTC');
        if (\is_string($configured) && \in_array($configured, \DateTimeZone::listIdentifiers(), true)) {
            $zone = new \DateTimeZone($configured);
        } else {
            $zones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, strtoupper(trim($country)));
            if ([] !== $zones) {
                $zone = new \DateTimeZone($zones[0]);
            }
        }

        return Clock::get()->withTimeZone($zone)->now()->format('Y-m-d');
    }
}
