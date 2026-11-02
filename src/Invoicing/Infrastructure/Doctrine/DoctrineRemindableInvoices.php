<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\RemindableInvoice;
use App\Invoicing\Application\RemindableInvoices;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\SendPurpose;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\LegalEntity;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * One query per firm. The contact flag is the firm's own when the contact has
 * invoicing values of its own for the firm, else the shared one. An address to
 * send to is the one the document holds, or an invoicing address of the contact:
 * its own for the firm when it has any, else the shared ones, as SendDraft picks
 * them. Times of earlier reminders are stored in the server's zone and compared
 * against the start of the firm's day, so the previous reminder counts on the
 * firm's own date.
 */
final readonly class DoctrineRemindableInvoices implements RemindableInvoices
{
    private const string SQL = <<<'SQL'
        SELECT i.id, r.steps, r.last_at
        FROM invoice i
        INNER JOIN party p ON p.id = i.customer_id
        LEFT JOIN party_firm_settings s ON s.party_id = i.customer_id AND s.legal_entity_id = i.legal_entity_id
        LEFT JOIN (
            SELECT invoice_id, MAX(reminder_step) AS steps, MAX(created_at) AS last_at
            FROM invoice_send
            WHERE tenant_id = :tenant AND purpose = :reminder
            GROUP BY invoice_id
        ) r ON r.invoice_id = i.id
        WHERE i.tenant_id = :tenant
            AND i.legal_entity_id = :firm
            AND i.deleted_at IS NULL
            AND i.delivered_at IS NOT NULL
            AND i.due_date >= :enabledOn
            AND (
                (s.invoicing_due_days IS NOT NULL AND (s.invoicing_send_reminders IS NULL OR s.invoicing_send_reminders = :yes))
                OR (s.invoicing_due_days IS NULL AND p.invoicing_send_reminders = :yes)
            )
            AND (
                (r.steps IS NULL AND i.due_date <= :firstDueBy)
                OR (r.steps < :maxSteps AND r.last_at < :nextBefore)
            )
            AND (
                (i.customer_email IS NOT NULL AND i.customer_email <> '')
                OR EXISTS (
                    SELECT 1 FROM party_email e
                    WHERE e.party_id = i.customer_id AND e.purpose = :invoices
                        AND (
                            e.legal_entity_id = i.legal_entity_id
                            OR (e.legal_entity_id IS NULL AND NOT EXISTS (
                                SELECT 1 FROM party_email o WHERE o.party_id = i.customer_id AND o.legal_entity_id = i.legal_entity_id
                            ))
                        )
                )
            )
        ORDER BY i.due_date, i.id
        SQL;

    public function __construct(private Connection $connection, private TenantContext $context)
    {
    }

    public function due(LegalEntity $firm, FirmReminders $settings, \DateTimeImmutable $today, int $limit): array
    {
        $enabledOn = $settings->enabledOn();
        if (!$settings->enabled() || !$enabledOn instanceof \DateTimeImmutable || $limit < 1) {
            return [];
        }
        $zone = $today->getTimezone();
        $day = new \DateTimeImmutable($today->format('Y-m-d'), $zone);
        $stored = new \DateTimeZone(date_default_timezone_get());
        // The previous reminder was on or before today minus the delay: before the start of the day after that.
        $nextBefore = $day->modify(\sprintf('-%d days', $settings->everyDays() - 1))->setTimezone($stored);

        $rows = $this->connection->fetchAllAssociative(
            self::SQL.' LIMIT '.$limit,
            [
                'tenant' => $this->context->current()->toString(),
                'firm' => $firm->id(),
                'reminder' => SendPurpose::Reminder->value,
                'invoices' => EmailPurpose::Invoices->value,
                'enabledOn' => $enabledOn,
                'firstDueBy' => $day->modify(\sprintf('-%d days', $settings->firstAfterDays())),
                'maxSteps' => FirmReminders::MAX_STEPS,
                'nextBefore' => $nextBefore,
                'yes' => true,
            ],
            [
                'enabledOn' => 'date_immutable',
                'firstDueBy' => 'date_immutable',
                'maxSteps' => ParameterType::INTEGER,
                'nextBefore' => 'datetime_immutable',
                'yes' => ParameterType::BOOLEAN,
            ],
        );

        return array_map(static function (array $row) use ($zone, $stored): RemindableInvoice {
            ['id' => $id, 'steps' => $steps, 'last_at' => $lastAt] = $row;
            if (!\is_string($id)) {
                throw new \UnexpectedValueException('An invoice row without its id.');
            }
            if (!\is_string($lastAt) || !is_numeric($steps)) {
                return new RemindableInvoice($id, 1, null);
            }
            $last = new \DateTimeImmutable($lastAt, $stored)->setTimezone($zone);

            return new RemindableInvoice($id, (int) $steps + 1, new \DateTimeImmutable($last->format('Y-m-d'), $zone));
        }, $rows);
    }
}
