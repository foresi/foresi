<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Invoicing\Application\PublicDocument;
use Foresi\Invoicing\Application\PublicDocuments;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\LinkAudience;
use Foresi\Invoicing\Domain\SendStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Reads the frozen columns of a send by plain SQL: the link is the only key, and
 * no entity is loaded.
 */
final readonly class DoctrinePublicDocuments implements PublicDocuments
{
    private const string FIND = <<<'SQL'
        SELECT id, token_hash, issuer_name, document_number, customer_name, amount_minor, currency, due_date, language, pdf_key, pdf_sha256,
            payment_method, bank_account, domestic_account, variable_symbol, payment_code,
            (SELECT i.payable_minor FROM invoice i WHERE i.id = s.invoice_id) AS payable_minor,
            (SELECT i.kind FROM invoice i WHERE i.id = s.invoice_id) AS kind,
            (SELECT i.deducts_advances FROM invoice i WHERE i.id = s.invoice_id) AS deducts_advances,
            (SELECT i.net_minor FROM invoice i WHERE i.id = s.invoice_id) AS net_minor,
            (SELECT i.vat_minor FROM invoice i WHERE i.id = s.invoice_id) AS vat_minor,
            (SELECT i.correction_reason FROM invoice i WHERE i.id = s.invoice_id) AS correction_reason,
            (SELECT o.number FROM invoice i JOIN invoice o ON o.id = i.corrected_invoice_id WHERE i.id = s.invoice_id) AS corrected_number,
            (SELECT COALESCE(SUM(a.amount_minor + a.settlement_minor), 0) FROM payment_allocation a JOIN payment p ON p.id = a.payment_id
                WHERE a.invoice_id = s.invoice_id AND p.cancelled_at IS NULL) AS paid_minor,
            (SELECT MAX(p.received_on) FROM payment_allocation a JOIN payment p ON p.id = a.payment_id
                WHERE a.invoice_id = s.invoice_id AND p.cancelled_at IS NULL) AS last_paid_on,
            (SELECT COALESCE(SUM(c.payable_minor), 0) FROM invoice c WHERE c.corrected_invoice_id = s.invoice_id AND c.deleted_at IS NULL) AS corrections_minor,
            (SELECT o.payable_minor FROM invoice i JOIN invoice o ON o.id = i.corrected_invoice_id WHERE i.id = s.invoice_id AND o.deleted_at IS NULL) AS original_payable_minor,
            (SELECT COALESCE(SUM(c.payable_minor), 0) FROM invoice i JOIN invoice c ON c.corrected_invoice_id = i.corrected_invoice_id
                WHERE i.id = s.invoice_id AND c.deleted_at IS NULL) AS original_corrections_minor,
            (SELECT COALESCE(SUM(a.amount_minor + a.settlement_minor), 0) FROM invoice i JOIN payment_allocation a ON a.invoice_id = i.corrected_invoice_id
                JOIN payment p ON p.id = a.payment_id WHERE i.id = s.invoice_id AND p.cancelled_at IS NULL) AS original_paid_minor
        FROM invoice_send s
        WHERE (token_hash = :hash OR copy_token_hash = :hash) AND revoked_at IS NULL AND status <> :cancelled
        SQL;

    private const string READ = 'UPDATE invoice_send SET first_read_at = COALESCE(first_read_at, :now), read_count = read_count + 1 WHERE id = :id';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function find(string $tokenHash): ?PublicDocument
    {
        $row = $this->connection->fetchAssociative(self::FIND, ['hash' => $tokenHash, 'cancelled' => SendStatus::Cancelled->value]);
        if (false === $row) {
            return null;
        }
        $currency = Currency::from($this->text($row, 'currency'));
        $payable = (int) $this->text($row, 'payable_minor');
        $paid = (int) $this->text($row, 'paid_minor');
        $lastPaidOn = $this->optional($row, 'last_paid_on');
        $kind = DocumentKind::from($this->text($row, 'kind'));
        $corrections = (int) $this->text($row, 'corrections_minor');
        $originalPayable = $this->optional($row, 'original_payable_minor');
        // Its invoice was paid above what the corrections leave due: the difference goes back.
        $originalToRefund = null !== $originalPayable && PaymentState::ToRefund === PaymentState::of(
            Money::ofMinor((int) $originalPayable, $currency),
            Money::ofMinor((int) $this->text($row, 'original_paid_minor'), $currency),
            Money::ofMinor((int) $this->text($row, 'original_corrections_minor'), $currency),
        );

        return new PublicDocument(
            $this->text($row, 'id'),
            hash_equals($this->text($row, 'token_hash'), $tokenHash) ? LinkAudience::Customer : LinkAudience::Copy,
            $this->text($row, 'issuer_name'),
            $this->text($row, 'document_number'),
            $this->text($row, 'customer_name'),
            (int) $this->text($row, 'amount_minor'),
            $currency,
            new \DateTimeImmutable(substr($this->text($row, 'due_date'), 0, 10)),
            DocumentLanguage::from(trim($this->text($row, 'language'))),
            $this->text($row, 'pdf_key'),
            $this->text($row, 'pdf_sha256'),
            PaymentMethod::from($this->text($row, 'payment_method')),
            $this->optional($row, 'bank_account'),
            $this->optional($row, 'domestic_account'),
            trim($this->text($row, 'variable_symbol')),
            $this->optional($row, 'payment_code'),
            // The invoice in the currency it was sent in: a document keeps its currency.
            PaymentState::ofDocument($kind, Money::ofMinor($payable, $currency), Money::ofMinor($paid, $currency), Money::ofMinor($corrections, $currency)),
            $kind->isPayable() ? max(0, $payable + $corrections - $paid) : 0,
            null === $lastPaidOn ? null : new \DateTimeImmutable(substr($lastPaidOn, 0, 10)),
            $kind,
            // A boolean of PostgreSQL, an integer of SQLite.
            \in_array($row['deducts_advances'], [true, 1, '1', 't', 'true'], true),
            $this->optional($row, 'corrected_number'),
            $this->optional($row, 'correction_reason'),
            (int) $this->text($row, 'net_minor'),
            (int) $this->text($row, 'vat_minor'),
            $corrections,
            $originalToRefund,
        );
    }

    public function recordRead(string $sendId): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->connection->executeStatement(self::READ, ['now' => $now, 'id' => $sendId], ['now' => 'datetime_immutable']);
    }

    public function exists(string $sendId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM invoice_send WHERE id = :id', ['id' => $sendId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function text(array $row, string $column): string
    {
        $value = $row[$column];
        if (\is_int($value)) {
            return (string) $value;
        }

        return \is_string($value) ? $value : throw new \UnexpectedValueException(\sprintf('The column %s of a send is empty.', $column));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function optional(array $row, string $column): ?string
    {
        return null === $row[$column] || '' === $row[$column] ? null : $this->text($row, $column);
    }
}
