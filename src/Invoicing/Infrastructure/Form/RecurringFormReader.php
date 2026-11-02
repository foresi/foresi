<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\TaxPointRule;
use App\Shared\Domain\Currency;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the form of a recurring template before anything is written. What it shares
 * with an invoice, the customer, the texts and the lines, the invoice form's reader
 * reads, so both refuse the same mistakes in the same words; this one adds the
 * name, the schedule and whom a sent invoice goes to. Without "to the customer"
 * ticked the invoice is only issued and waits under "To send" (RecurringDelivery).
 * An unticked box is not sent at all, so each reads as ticked only when it says 1. A schedule it cannot read is a form error, never an
 * exception, and the input then carries a neutral one.
 */
final readonly class RecurringFormReader
{
    public const array SCHEDULE_FIELDS = ['firstIssueOn', 'unit', 'every', 'onLastDayOfMonth', 'taxPoint', 'dueDays', 'end', 'endOn', 'endAfter'];
    /** The boxes of "Odesílání faktury". */
    public const array SENDING_FIELDS = ['sendToCustomer', 'copyToMe', 'copyToOthers'];
    /** The fields of this form the invoice form does not have, labelled as recurring.field.*. */
    public const array OWN_FIELDS = ['name', ...self::SCHEDULE_FIELDS, ...self::SENDING_FIELDS];

    /** What the invoice reader says about fields this form has too. */
    private const array SHARED_FIELDS = ['description', 'textBefore', 'textAfter', 'paymentMethod', 'language'];
    private const string WHOLE = '/^\d{1,4}$/D';

    public function __construct(private InvoiceFormReader $invoices)
    {
    }

    /**
     * @param \DateTimeImmutable $today the tenant's today, whose zone the dates take
     */
    public function read(Request $request, Currency $currency, \DateTimeImmutable $today): RecurringSubmission
    {
        $shared = $this->invoices->read($request, $currency);
        $errors = array_filter($shared->errors, static fn (string $field): bool => str_starts_with($field, 'lines.') || \in_array($field, self::SHARED_FIELDS, true), \ARRAY_FILTER_USE_KEY);

        $schedule = $this->schedule($request);
        $recurrence = $this->recurrence($schedule, $today->getTimezone(), $errors);
        $taxPoint = TaxPointRule::tryFrom($schedule['taxPoint']);
        if (!$taxPoint instanceof TaxPointRule) {
            $errors['taxPoint'] = 'recurring.problem.choice_invalid';
        }
        $delivery = '1' === $schedule['sendToCustomer'] ? RecurringDelivery::Send : RecurringDelivery::Review;
        $name = trim(preg_replace('/\s+/u', ' ', $request->request->getString('name')) ?? '');
        if ('' === $name) {
            $errors['name'] = 'recurring.problem.name_missing';
        } elseif (mb_strlen($name) > RecurringInvoice::MAX_NAME_LENGTH) {
            $errors['name'] = 'recurring.problem.name_too_long';
        }
        $dueDays = $this->whole($schedule['dueDays']);
        if (null === $dueDays) {
            $errors['dueDays'] = 'recurring.problem.due_days_invalid';
        }

        $typed = $shared->input;
        $input = new RecurringInput(
            $typed->firmId,
            $typed->seriesId,
            $typed->customerId,
            $typed->paymentMethod,
            $typed->bankAccount,
            $typed->constantSymbol,
            $typed->orderNumber,
            $typed->description,
            $typed->textBefore,
            $typed->textAfter,
            $typed->language,
            $typed->lines,
            $recurrence ?? $this->neutral($today),
            $taxPoint ?? TaxPointRule::IssueDate,
            $dueDays ?? 0,
            $delivery,
            mb_substr($name, 0, RecurringInvoice::MAX_NAME_LENGTH),
            '1' === $schedule['copyToMe'],
            '1' === $schedule['copyToOthers'],
        );

        return new RecurringSubmission([...$shared->values, ...$schedule, 'name' => $request->request->getString('name'), 'copyOf' => trim($request->request->getString('copyOf'))], $errors, $input, $shared->version);
    }

    /**
     * @return array<string, string>
     */
    private function schedule(Request $request): array
    {
        $values = [];
        foreach ([...self::SCHEDULE_FIELDS, ...self::SENDING_FIELDS, 'dueDaysSet'] as $field) {
            $values[$field] = trim($request->request->getString($field));
        }

        return $values;
    }

    /**
     * @param array<string, string> $schedule
     * @param array<string, string> $errors
     */
    private function recurrence(array $schedule, \DateTimeZone $zone, array &$errors): ?Recurrence
    {
        $before = \count($errors);
        $first = $this->invoices->date($schedule['firstIssueOn'], 'firstIssueOn', $errors);
        if ('' === $schedule['firstIssueOn']) {
            $errors['firstIssueOn'] = 'recurring.problem.first_issue_missing';
        }
        $unit = RecurrenceUnit::tryFrom($schedule['unit']);
        if (!$unit instanceof RecurrenceUnit) {
            $errors['unit'] = 'recurring.problem.choice_invalid';
        }
        $every = $this->whole($schedule['every']);
        if (null === $every || $every < 1 || $every > Recurrence::MAX_EVERY) {
            $errors['every'] = 'recurring.problem.every_invalid';
        }
        $end = RecurrenceEnd::tryFrom($schedule['end']);
        $endOn = null;
        $endAfter = null;
        if (RecurrenceEnd::OnDate === $end) {
            $endOn = $this->invoices->date($schedule['endOn'], 'endOn', $errors);
            if ('' === $schedule['endOn']) {
                $errors['endOn'] = 'recurring.problem.end_on_missing';
            } elseif ($endOn instanceof \DateTimeImmutable && $first instanceof \DateTimeImmutable && $endOn < $first) {
                $errors['endOn'] = 'recurring.problem.end_on_before_first';
            }
        } elseif (RecurrenceEnd::AfterCount === $end) {
            $endAfter = $this->whole($schedule['endAfter']);
            if (null === $endAfter || $endAfter < 1 || $endAfter > Recurrence::MAX_END_AFTER) {
                $errors['endAfter'] = 'recurring.problem.end_after_invalid';
            }
        } elseif (!$end instanceof RecurrenceEnd) {
            $errors['end'] = 'recurring.problem.choice_invalid';
        }
        if (\count($errors) > $before || !$first instanceof \DateTimeImmutable || !$unit instanceof RecurrenceUnit || null === $every || !$end instanceof RecurrenceEnd) {
            return null;
        }
        // The option belongs to months and years; on days and weeks the form hides it and it means nothing.
        $lastDay = '1' === $schedule['onLastDayOfMonth'] && \in_array($unit, [RecurrenceUnit::Month, RecurrenceUnit::Year], true);

        return new Recurrence($this->inZone($first, $zone), $unit, $every, $lastDay, $end, $endOn instanceof \DateTimeImmutable ? $this->inZone($endOn, $zone) : null, $endAfter);
    }

    private function neutral(\DateTimeImmutable $today): Recurrence
    {
        return new Recurrence($today, RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null);
    }

    private function inZone(\DateTimeImmutable $date, \DateTimeZone $zone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), $zone);
    }

    private function whole(string $typed): ?int
    {
        return 1 === preg_match(self::WHOLE, $typed) ? (int) $typed : null;
    }
}
