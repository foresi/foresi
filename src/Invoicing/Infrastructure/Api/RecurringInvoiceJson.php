<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api;

use Foresi\Invoicing\Application\Api\ApiLines;
use Foresi\Invoicing\Application\Api\RecurringDraft;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\PreviewRow;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Infrastructure\Api\JsonValues;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A recurring invoice as the API and MCP answer it: the template, its schedule,
 * its delivery and its lines in the shape the writes take them, and the next
 * invoices it issues. A preview answers the same shape without an id, with what
 * the rules would refuse.
 */
final readonly class RecurringInvoiceJson
{
    /** How many next issues a template and a preview show, as the form does. */
    public const int NEXT = 5;

    public function __construct(private RecurringWrites $writes, private PartyQuery $parties, private TranslatorInterface $translator)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(RecurringInvoice $template): array
    {
        $failure = $template->lastFailureAt();

        return [
            'id' => $template->id(),
            'version' => $template->version(),
            'name' => $template->name(),
            'state' => $template->state()->value,
            'trashed' => $template->isTrashed(),
            'currency' => $template->currency()->value,
            'customer' => ['id' => $template->customer()->id(), 'name' => $template->customer()->displayName()],
            'series' => $template->series() instanceof \Foresi\Invoicing\Domain\Series ? ['id' => $template->series()->id(), 'name' => $template->series()->name()] : null,
            ...$this->content($template->paymentMethod()->value, $template->bankAccount(), $template->constantSymbol(), $template->orderNumber(), $template->description(), $template->textBefore(), $template->textAfter(), $template->language()->value),
            'schedule' => self::schedule($template->recurrence()),
            'tax_point' => $template->taxPoint()->value,
            'due_days' => $template->dueDays(),
            'delivery' => $template->delivery()->value,
            'copy_to_me' => $template->copyToMe(),
            'copy_to_others' => $template->copyToOthers(),
            'next_issue_on' => JsonValues::date($template->nextOccurrence()),
            'last_issue_on' => JsonValues::date($template->lastOccurrence()),
            'issued_count' => $template->issuedCount(),
            'paused_at' => JsonValues::timestamp($template->pausedAt()),
            'ended_at' => JsonValues::timestamp($template->endedAt()),
            'last_failure' => $failure instanceof \DateTimeImmutable ? ['at' => JsonValues::timestamp($failure), 'code' => $template->lastFailureCode()] : null,
            'lines' => array_map(static fn (RecurringInvoiceLine $line): array => ApiLines::fields($line->text(), $line->quantity(), $line->unit(), $line->price(), $line->priceType(), $line->treatment(), $line->rateKind(), $line->supplyCode(), $line->discount()), $this->writes->lines($template)),
            'next_issues' => array_map($this->issue(...), $this->writes->nextIssues($template, self::NEXT)),
            'created_at' => JsonValues::timestamp($template->createdAt()),
        ];
    }

    /**
     * A row of a list: the template without its lines and texts.
     *
     * @return array<string, mixed>
     */
    public static function item(RecurringInvoice $template): array
    {
        return [
            'id' => $template->id(),
            'name' => $template->name(),
            'state' => $template->state()->value,
            'customer' => ['id' => $template->customer()->id(), 'name' => $template->customer()->displayName()],
            'description' => $template->description(),
            'schedule' => self::schedule($template->recurrence()),
            'delivery' => $template->delivery()->value,
            'next_issue_on' => JsonValues::date($template->nextOccurrence()),
            'last_issue_on' => JsonValues::date($template->lastOccurrence()),
            'issued_count' => $template->issuedCount(),
            'failing' => $template->lastFailureAt() instanceof \DateTimeImmutable,
        ];
    }

    /**
     * What a create with the fields sent would save, and whether it would.
     *
     * @return array<string, mixed>
     */
    public function draft(RecurringDraft $draft): array
    {
        $input = $draft->input;
        $customer = $this->parties->find($input->customerId);

        return [
            'saves' => [] === $draft->problems,
            'problems' => array_map($this->problem(...), $draft->problems),
            'customer' => ['id' => $input->customerId, 'name' => $customer instanceof Party ? $customer->displayName() : null],
            'series_id' => $input->seriesId,
            'name' => $input->name,
            ...$this->content($input->paymentMethod->value, $input->bankAccount, $input->constantSymbol, $input->orderNumber, $input->description, $input->textBefore, $input->textAfter, $input->language->value),
            'schedule' => self::schedule($input->recurrence),
            'tax_point' => $input->taxPoint->value,
            'due_days' => $input->dueDays,
            'delivery' => $input->delivery->value,
            'copy_to_me' => $input->copyToMe,
            'copy_to_others' => $input->copyToOthers,
            'lines' => array_map(static fn (LineInput $line): array => ApiLines::fields($line->text, $line->quantity, $line->unit, $line->price, $line->priceType, $line->treatment, $line->rateKind, $line->supplyCode, $line->discount), $input->lines),
            'next_issues' => array_map($this->issue(...), $draft->issues),
        ];
    }

    /**
     * A problem of RecurringRules as "parameter: reason — message", in English.
     */
    public function problem(IssueProblem $problem): string
    {
        $reason = preg_replace('/^(invoice|recurring)\.problem\./', '', $problem->key) ?? $problem->key;

        return $problem->field.': '.$reason.' — '.$this->translator->trans($problem->key, $problem->parameters, null, 'en');
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $paymentMethod, ?string $bankAccount, string $constantSymbol, string $orderNumber, string $description, string $textBefore, string $textAfter, string $language): array
    {
        return [
            'payment_method' => $paymentMethod,
            'bank_account' => $bankAccount,
            'constant_symbol' => $constantSymbol,
            'order_number' => $orderNumber,
            'description' => $description,
            'text_before' => $textBefore,
            'text_after' => $textAfter,
            'language' => $language,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schedule(Recurrence $recurrence): array
    {
        return [
            'first_issue_on' => JsonValues::date($recurrence->firstIssueOn()),
            'repeat' => $recurrence->unit()->value,
            'every' => $recurrence->every(),
            'last_day_of_month' => $recurrence->onLastDayOfMonth(),
            'end' => $recurrence->end()->value,
            'ends_on' => JsonValues::date($recurrence->endOn()),
            'ends_after' => $recurrence->endAfter(),
        ];
    }

    /**
     * @return array{issue_date: ?string, tax_point_date: ?string, due_date: ?string, description: string}
     */
    private function issue(PreviewRow $row): array
    {
        return [
            'issue_date' => JsonValues::date($row->issue),
            'tax_point_date' => JsonValues::date($row->taxPoint),
            'due_date' => JsonValues::date($row->due),
            'description' => $row->description,
        ];
    }
}
