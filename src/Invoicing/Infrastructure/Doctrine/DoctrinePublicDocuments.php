<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\PublicDocument;
use App\Invoicing\Application\PublicDocuments;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Domain\SendStatus;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Infrastructure\Doctrine\TenantFilterBypass;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Reads the frozen columns of a send by plain SQL inside the sanctioned bypass:
 * the link is the only key, and no entity is loaded, so nothing of another tenant
 * stays in the session.
 */
final readonly class DoctrinePublicDocuments implements PublicDocuments
{
    private const string FIND = <<<'SQL'
        SELECT id, tenant_id, token_hash, issuer_name, document_number, customer_name, amount_minor, currency, due_date, language, pdf_key, pdf_sha256,
            payment_method, bank_account, domestic_account, variable_symbol, payment_code,
            (SELECT i.payable_minor FROM invoice i WHERE i.id = s.invoice_id) AS payable_minor,
            (SELECT i.kind FROM invoice i WHERE i.id = s.invoice_id) AS kind,
            (SELECT COALESCE(SUM(a.amount_minor + a.settlement_minor), 0) FROM payment_allocation a JOIN payment p ON p.id = a.payment_id
                WHERE a.invoice_id = s.invoice_id AND p.cancelled_at IS NULL) AS paid_minor,
            (SELECT MAX(p.received_on) FROM payment_allocation a JOIN payment p ON p.id = a.payment_id
                WHERE a.invoice_id = s.invoice_id AND p.cancelled_at IS NULL) AS last_paid_on
        FROM invoice_send s
        WHERE (token_hash = :hash OR copy_token_hash = :hash) AND revoked_at IS NULL AND status <> :cancelled
        SQL;

    private const string READ = 'UPDATE invoice_send SET first_read_at = COALESCE(first_read_at, :now), read_count = read_count + 1 WHERE id = :id';

    public function __construct(
        private Connection $connection,
        private TenantFilterBypass $bypass,
        private ClockInterface $clock,
    ) {
    }

    public function find(string $tokenHash): ?PublicDocument
    {
        $row = $this->bypass->run(fn (): array|false => $this->connection->fetchAssociative(self::FIND, ['hash' => $tokenHash, 'cancelled' => SendStatus::Cancelled->value]));
        if (false === $row) {
            return null;
        }
        $currency = Currency::from($this->text($row, 'currency'));
        $payable = (int) $this->text($row, 'payable_minor');
        $paid = (int) $this->text($row, 'paid_minor');
        $lastPaidOn = $this->optional($row, 'last_paid_on');
        $kind = DocumentKind::from($this->text($row, 'kind'));

        return new PublicDocument(
            $this->text($row, 'id'),
            $this->text($row, 'tenant_id'),
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
            PaymentState::ofDocument($kind, Money::ofMinor($payable, $currency), Money::ofMinor($paid, $currency)),
            $kind->isPayable() ? max(0, $payable - $paid) : 0,
            null === $lastPaidOn ? null : new \DateTimeImmutable(substr($lastPaidOn, 0, 10)),
            $kind,
        );
    }

    public function recordRead(string $sendId): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->bypass->run(fn (): int|string => $this->connection->executeStatement(self::READ, ['now' => $now, 'id' => $sendId], ['now' => 'datetime_immutable']));
    }

    public function tenantOf(string $sendId): ?string
    {
        $tenant = $this->bypass->run(fn (): mixed => $this->connection->fetchOne('SELECT tenant_id FROM invoice_send WHERE id = :id', ['id' => $sendId]));

        return \is_string($tenant) ? $tenant : null;
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
