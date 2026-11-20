<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * One instance holds one firm (ADR 0039), so the 22 module tables that named
 * their firm lose the column: every row belonged to the same firm, which the
 * guard (Version20261118210001) made sure of. The foreign keys into
 * legal_entity and the indexes that held the column go with it.
 *
 * The unique indexes that led with the firm are rebuilt on their remaining
 * columns under the same names (the mail template one renamed, as it named the
 * firm), so a number, a series name or an imported record stays unique in the
 * instance. The plain indexes that led with the firm are rebuilt on their
 * remaining column under names without it.
 *
 * Seven tables held one row per firm, unique on the firm alone. They now hold
 * one row: a one_row column, always true, unique, and on PostgreSQL checked to
 * be true, so a second row is refused. SQLite cannot add a check to a table,
 * so there the unique index stops a second true row and the code writes only
 * true.
 *
 * Going down brings the column back, every row given the one firm, with the
 * foreign keys and indexes as they were. It stops when a table holds rows and
 * there is no firm to give them.
 */
final class Version20261120200001 extends AbstractMigration
{
    /** The tables that lose the column, each with the indexes that held it: name => [columns, unique]. */
    private const array FIRM_INDEXES = [
        'abandoned_number' => [
            'idx_e32157c96dec420c' => [['legal_entity_id'], false],
            'uniq_abandoned_number' => [['legal_entity_id', 'number_key'], true],
        ],
        'bank_movement' => [
            'idx_bank_movement_firm' => [['legal_entity_id'], false],
        ],
        'cash_flow_minimum_balance' => [
            'uniq_cash_flow_minimum_balance_firm' => [['legal_entity_id'], true],
        ],
        'cash_flow_planned_item' => [
            'idx_cash_flow_planned_item_firm' => [['legal_entity_id'], false],
        ],
        'evidence_series' => [
            'uniq_evidence_series_firm' => [['legal_entity_id'], true],
        ],
        'firm_bank_account' => [
            'idx_6a741c8c6dec420c' => [['legal_entity_id'], false],
            'uniq_firm_bank_account_iban' => [['legal_entity_id', 'iban'], true],
            'uniq_firm_bank_account_primary' => [['legal_entity_id', 'primary_flag'], true],
        ],
        'firm_bank_pairing' => [
            'uniq_firm_bank_pairing_firm' => [['legal_entity_id'], true],
        ],
        'firm_invoicing' => [
            'uniq_firm_invoicing_firm' => [['legal_entity_id'], true],
        ],
        'firm_mail_server' => [
            'uniq_firm_mail_server_firm' => [['legal_entity_id'], true],
        ],
        'firm_reminders' => [
            'uniq_firm_reminders_firm' => [['legal_entity_id'], true],
        ],
        'idoklad_connection' => [
            'uniq_idoklad_connection_firm' => [['legal_entity_id'], true],
        ],
        'idoklad_import_run' => [
            'idx_c19169ed6dec420c' => [['legal_entity_id'], false],
            'idx_idoklad_import_run_firm' => [['legal_entity_id', 'started_at'], false],
        ],
        'idoklad_job' => [
            'idx_e2b2423e6dec420c' => [['legal_entity_id'], false],
            'idx_idoklad_job_firm' => [['legal_entity_id', 'requested_at'], false],
        ],
        'idoklad_link' => [
            'idx_44b294d66dec420c' => [['legal_entity_id'], false],
            'uniq_idoklad_link' => [['legal_entity_id', 'resource', 'external_id'], true],
        ],
        'idoklad_pull' => [
            'idx_e713d1c46dec420c' => [['legal_entity_id'], false],
            'idx_idoklad_pull_firm' => [['legal_entity_id', 'started_at'], false],
        ],
        'idoklad_record' => [
            'idx_3f4023576dec420c' => [['legal_entity_id'], false],
            'uniq_idoklad_record' => [['legal_entity_id', 'resource', 'external_id'], true],
        ],
        'invoice' => [
            'idx_906517446dec420c' => [['legal_entity_id'], false],
            'idx_invoice_firm_date' => [['legal_entity_id', 'issue_date'], false],
            'idx_invoice_firm_tax_point' => [['legal_entity_id', 'tax_point_date'], false],
            'uniq_invoice_import' => [['legal_entity_id', 'import_source', 'import_external_id'], true],
            'uniq_invoice_number' => [['legal_entity_id', 'number_key'], true],
        ],
        'mail_template' => [
            'idx_4ab7decb6dec420c' => [['legal_entity_id'], false],
            'uniq_mail_template_firm_kind_language' => [['legal_entity_id', 'kind', 'language'], true],
        ],
        'payment' => [
            'idx_payment_firm' => [['legal_entity_id'], false],
            'idx_payment_firm_received' => [['legal_entity_id', 'received_on'], false],
            'uniq_payment_import' => [['legal_entity_id', 'import_source', 'import_external_id'], true],
        ],
        'received_document' => [
            'idx_ca7c802c6dec420c' => [['legal_entity_id'], false],
            'idx_received_document_firm_deduction' => [['legal_entity_id', 'deduction_period'], false],
            'idx_received_document_firm_received' => [['legal_entity_id', 'received_date'], false],
            'uniq_received_document_evidence' => [['legal_entity_id', 'evidence_number'], true],
        ],
        'recurring_invoice' => [
            'idx_recurring_invoice_firm' => [['legal_entity_id'], false],
        ],
        'series' => [
            'idx_series_firm' => [['legal_entity_id'], false],
            'uniq_series_default' => [['legal_entity_id', 'kind', 'default_flag'], true],
            'uniq_series_name' => [['legal_entity_id', 'name'], true],
        ],
    ];

    /** The indexes rebuilt without the firm: table => name => [columns, unique]. */
    private const array REBUILT = [
        'abandoned_number' => ['uniq_abandoned_number' => [['number_key'], true]],
        'firm_bank_account' => [
            'uniq_firm_bank_account_iban' => [['iban'], true],
            'uniq_firm_bank_account_primary' => [['primary_flag'], true],
        ],
        'idoklad_import_run' => ['idx_idoklad_import_run_started' => [['started_at'], false]],
        'idoklad_job' => ['idx_idoklad_job_requested' => [['requested_at'], false]],
        'idoklad_link' => ['uniq_idoklad_link' => [['resource', 'external_id'], true]],
        'idoklad_pull' => ['idx_idoklad_pull_started' => [['started_at'], false]],
        'idoklad_record' => ['uniq_idoklad_record' => [['resource', 'external_id'], true]],
        'invoice' => [
            'idx_invoice_issue_date' => [['issue_date'], false],
            'idx_invoice_tax_point' => [['tax_point_date'], false],
            'uniq_invoice_import' => [['import_source', 'import_external_id'], true],
            'uniq_invoice_number' => [['number_key'], true],
        ],
        'mail_template' => ['uniq_mail_template_kind_language' => [['kind', 'language'], true]],
        'payment' => [
            'idx_payment_received' => [['received_on'], false],
            'uniq_payment_import' => [['import_source', 'import_external_id'], true],
        ],
        'received_document' => [
            'idx_received_document_deduction' => [['deduction_period'], false],
            'idx_received_document_received' => [['received_date'], false],
            'uniq_received_document_evidence' => [['evidence_number'], true],
        ],
        'series' => [
            'uniq_series_default' => [['kind', 'default_flag'], true],
            'uniq_series_name' => [['name'], true],
        ],
    ];

    /** The tables of one row, the settings of the instance's firm. */
    private const array ONE_ROW = [
        'cash_flow_minimum_balance', 'evidence_series', 'firm_bank_pairing', 'firm_invoicing',
        'firm_mail_server', 'firm_reminders', 'idoklad_connection',
    ];

    public function getDescription(): string
    {
        return 'Drop legal_entity_id from the 22 module tables: one instance, one firm';
    }

    public function preUp(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM legal_entity') > 1,
            'The database holds more than one firm; an instance holds one (ADR 0039).',
        );
    }

    public function up(Schema $schema): void
    {
        foreach (self::FIRM_INDEXES as $name => $indexes) {
            $table = $schema->getTable($name);
            $table->dropForeignKey('fk_'.$name.'_firm');
            foreach (array_keys($indexes) as $index) {
                $table->dropIndex($index);
            }
            $table->dropColumn('legal_entity_id');
            foreach (self::REBUILT[$name] ?? [] as $index => [$columns, $unique]) {
                $unique ? $table->addUniqueIndex($columns, $index) : $table->addIndex($columns, $index);
            }
        }
        foreach (self::ONE_ROW as $name) {
            $table = $schema->getTable($name);
            $table->addColumn('one_row', Types::BOOLEAN, ['default' => true]);
            $table->addUniqueIndex(['one_row'], 'uniq_'.$name.'_one_row');
        }
    }

    public function postUp(Schema $schema): void
    {
        // A dry run changes no schema, so there is no column to check.
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            || !\array_key_exists('one_row', $this->connection->createSchemaManager()->listTableColumns('firm_invoicing'))) {
            return;
        }

        foreach (self::ONE_ROW as $name) {
            $this->connection->executeStatement(\sprintf('ALTER TABLE %1$s ADD CONSTRAINT chk_%1$s_one_row CHECK (one_row)', $name));
        }
    }

    public function preDown(Schema $schema): void
    {
        if (false !== $this->connection->fetchOne('SELECT 1 FROM legal_entity')) {
            return;
        }
        foreach (array_keys(self::FIRM_INDEXES) as $name) {
            $this->abortIf(
                false !== $this->connection->fetchOne('SELECT 1 FROM '.$name),
                \sprintf('The table %s holds rows, and there is no firm to give them.', $name),
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ONE_ROW as $name) {
            $table = $schema->getTable($name);
            $table->dropIndex('uniq_'.$name.'_one_row');
            $table->dropColumn('one_row');
        }
        foreach (self::FIRM_INDEXES as $name => $indexes) {
            $table = $schema->getTable($name);
            foreach (array_keys(self::REBUILT[$name] ?? []) as $index) {
                $table->dropIndex($index);
            }
            // Nullable until postDown() has given every row the firm.
            $table->addColumn('legal_entity_id', Types::GUID, ['notnull' => false]);
            foreach ($indexes as $index => [$columns, $unique]) {
                $unique ? $table->addUniqueIndex($columns, $index) : $table->addIndex($columns, $index);
            }
            $table->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_'.$name.'_firm');
        }
    }

    public function postDown(Schema $schema): void
    {
        $manager = $this->connection->createSchemaManager();
        // A dry run changes no schema, so there is no column to fill.
        if (!\array_key_exists('legal_entity_id', $manager->listTableColumns('invoice'))) {
            return;
        }

        $firm = $this->connection->fetchOne('SELECT id FROM legal_entity ORDER BY id');
        if (false !== $firm) {
            foreach (array_keys(self::FIRM_INDEXES) as $name) {
                $this->connection->executeStatement('UPDATE '.$name.' SET legal_entity_id = ?', [$firm]);
            }
        }

        $from = $manager->introspectSchema();
        $to = clone $from;
        foreach (array_keys(self::FIRM_INDEXES) as $name) {
            $to->getTable($name)->modifyColumn('legal_entity_id', ['notnull' => true]);
        }
        foreach ($this->connection->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($from, $to)) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
