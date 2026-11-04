<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Doctrine\RunsOnlyForReal;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the bank accounts of each firm from the JSON list on the firm into
 * firm_bank_account, then drops the list. The accounts keep their order and the
 * first one is the primary one. The list held no currency, so each account gets
 * the home currency of the firm's country, as far as it is one Foresi knows, and
 * CZK otherwise: an owner corrects the currency of any other account in the
 * firm's bank settings. An IBAN listed twice becomes one account.
 *
 * The copy runs in up(), so RunsOnlyForReal refuses --dry-run and --write-sql.
 * down() writes the active accounts back into the list, primary first, and
 * removes the rows; labels, currencies and archived accounts are lost.
 */
final class Version20261103020002 extends AbstractMigration implements RunsOnlyForReal
{
    /**
     * The countries whose home currency is one Foresi knows, as they were when
     * this migration was written; frozen, so it migrates the same way later.
     */
    private const array HOME_CURRENCIES = [
        'CZ' => 'CZK',
        'US' => 'USD',
        'AT' => 'EUR', 'BE' => 'EUR', 'BG' => 'EUR', 'CY' => 'EUR', 'DE' => 'EUR', 'EE' => 'EUR', 'ES' => 'EUR',
        'FI' => 'EUR', 'FR' => 'EUR', 'GR' => 'EUR', 'HR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR', 'LT' => 'EUR',
        'LU' => 'EUR', 'LV' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR', 'PT' => 'EUR', 'SI' => 'EUR', 'SK' => 'EUR',
    ];

    public function getDescription(): string
    {
        return 'Move the bank accounts of a firm into records';
    }

    public function up(Schema $schema): void
    {
        $firms = $this->connection->fetchAllAssociative('SELECT id, tenant_id, bank_accounts, address_country_code FROM legal_entity ORDER BY id');
        foreach ($firms as $firm) {
            $currency = self::HOME_CURRENCIES[strtoupper(trim((string) $firm['address_country_code']))] ?? 'CZK';
            $listed = json_decode((string) $firm['bank_accounts'], true, flags: \JSON_THROW_ON_ERROR);
            $ibans = array_values(array_unique(array_filter(\is_array($listed) ? $listed : [], is_string(...))));
            foreach ($ibans as $position => $iban) {
                $this->connection->insert('firm_bank_account', [
                    'id' => Id::generate(),
                    'tenant_id' => $firm['tenant_id'],
                    'legal_entity_id' => $firm['id'],
                    'iban' => $iban,
                    'currency' => $currency,
                    'label' => '',
                    'primary_flag' => 0 === $position ? true : null,
                    'position' => $position,
                    'archived_at' => null,
                ], ['primary_flag' => Types::BOOLEAN, 'position' => Types::INTEGER]);
            }
        }

        // Queued, so it runs after the copy above.
        $schema->getTable('legal_entity')->dropColumn('bank_accounts');
    }

    public function down(Schema $schema): void
    {
        $lists = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id FROM legal_entity ORDER BY id') as $firm) {
            $lists[(string) $firm['id']] = [];
        }
        $accounts = $this->connection->fetchAllAssociative('SELECT legal_entity_id, iban, primary_flag FROM firm_bank_account WHERE archived_at IS NULL ORDER BY position, id');
        foreach ($accounts as $account) {
            $firm = (string) $account['legal_entity_id'];
            if ((bool) $account['primary_flag']) {
                array_unshift($lists[$firm], (string) $account['iban']);
            } else {
                $lists[$firm][] = (string) $account['iban'];
            }
        }

        // Done here, not queued: the list is filled before it may become NOT NULL.
        $this->connection->executeStatement('DELETE FROM firm_bank_account');
        $this->alterFirms(static fn (Table $firms): mixed => $firms->addColumn('bank_accounts', Types::JSON, ['notnull' => false]));
        foreach ($lists as $id => $ibans) {
            $this->connection->update('legal_entity', ['bank_accounts' => json_encode($ibans, \JSON_THROW_ON_ERROR)], ['id' => $id]);
        }
        $this->alterFirms(static fn (Table $firms): mixed => $firms->modifyColumn('bank_accounts', ['notnull' => true]));
    }

    /**
     * @param callable(Table): mixed $change changes the table it is given
     */
    private function alterFirms(callable $change): void
    {
        $manager = $this->connection->createSchemaManager();
        $from = $manager->introspectTable('legal_entity');
        $to = clone $from;
        $change($to);
        foreach ($this->connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($from, $to)) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
