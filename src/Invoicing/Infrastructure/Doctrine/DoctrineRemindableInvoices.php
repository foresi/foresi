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
use App\Shared\Domain\Money;
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
 * firm's own date. Every table is read in the active tenant, the joined ones too,
 * so a row of another tenant that names the same contact never counts.
 *
 * Invoices that owe nothing are left out in SQL by the balances' own condition,
 * and so are those whose payable in the minimum's currency is below the minimum:
 * nothing is owed beyond the payable. So settled invoices never fill the limit
 * ahead of a due one. Pages go on after the last invoice of the previous one, by
 * due date and id, so reminders queued meanwhile do not shift them.
 */
final readonly class DoctrineRemindableInvoices implements RemindableInvoices
{
    private const string SQL = <<<'SQL'
        SELECT i.id, r.steps, r.last_at
        FROM invoice i
        INNER JOIN party p ON p.id = i.customer_id AND p.tenant_id = :tenant
        LEFT JOIN party_firm_settings s ON s.party_id = i.customer_id AND s.legal_entity_id = i.legal_entity_id AND s.tenant_id = :tenant
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
                    WHERE e.tenant_id = :tenant AND e.party_id = i.customer_id AND e.purpose = :invoices
                        AND (
                            e.legal_entity_id = i.legal_entity_id
                            OR (e.legal_entity_id IS NULL AND NOT EXISTS (
                                SELECT 1 FROM party_email o WHERE o.tenant_id = :tenant AND o.party_id = i.customer_id AND o.legal_entity_id = i.legal_entity_id
                            ))
                        )
                )
            )
        SQL;
    private const string AFTER = <<<'SQL'
        AND (
            i.due_date > (SELECT a.due_date FROM invoice a WHERE a.tenant_id = :tenant AND a.id = :after)
            OR (i.due_date = (SELECT a.due_date FROM invoice a WHERE a.tenant_id = :tenant AND a.id = :after) AND i.id > :after)
        )
        SQL;
    private const string MINIMUM = ' AND (i.currency <> :minimumCurrency OR i.payable_minor >= :minimum)';

    public function __construct(private Connection $connection, private TenantContext $context, private OwingInvoiceCondition $owing)
    {
    }

    public function due(LegalEntity $firm, FirmReminders $settings, \DateTimeImmutable $today, int $limit, ?string $afterId = null): array
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

        $sql = self::SQL.' AND ('.$this->owing->owing('i').')';
        $parameters = [];
        $minimum = $settings->minimum();
        if ($minimum instanceof Money) {
            $sql .= self::MINIMUM;
            $parameters = ['minimumCurrency' => $minimum->currency()->value, 'minimum' => $minimum->minor()];
        }
        if (null !== $afterId) {
            $sql .= ' '.self::AFTER;
            $parameters['after'] = $afterId;
        }

        $rows = $this->connection->fetchAllAssociative(
            $sql.' ORDER BY i.due_date, i.id LIMIT '.$limit,
            $parameters + [
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
                'minimum' => ParameterType::INTEGER,
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
