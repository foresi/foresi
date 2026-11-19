<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * What the invoice writes refuse over the API and MCP (ADR 0032). A refusal of
 * IssueRules carries every problem it found: the first is the reason and the
 * field, and details.problems lists them all as "parameter: reason", so an
 * agent can fix everything in one retry. A problem's reason is its translation
 * key without "invoice.problem.", such as number_locked. A value the API could
 * not read is refused here before any rule runs.
 */
final class InvoicingRefused extends \DomainException implements Refused
{
    /** A date is not YYYY-MM-DD. */
    public const string DATE = 'date_invalid';
    /** An amount, a quantity or a discount is not a decimal of the allowed places. */
    public const string NUMBER = 'number_invalid';
    /** A currency is not an ISO 4217 code Foresi knows. */
    public const string CURRENCY = 'currency_unsupported';
    /** A line is not an object of the documented fields. */
    public const string LINE = 'line_invalid';
    /** The firm is not the instance's. */
    public const string FIRM_UNKNOWN = 'firm_unknown';
    /** The organisation has no such document. */
    public const string NOT_FOUND = 'not_found';
    /** Only the owners and members of the organisation work with invoices. */
    public const string FORBIDDEN = 'forbidden';
    /** The document is of a kind the API does not write. */
    public const string KIND = 'kind_not_writable';
    /** A sent document, or one in the trash, cannot go to the trash. */
    public const string NOT_TRASHABLE = 'not_trashable';
    /** A document with payments goes to the trash only once they are removed. */
    public const string HAS_PAYMENTS = 'has_payments';
    /** A tax document of a cancelled payment stays in the trash. */
    public const string CANCELLED = 'cancelled';
    /** The document is in the trash, so it is not marked sent. */
    public const string TRASHED = 'trashed';
    /** The reason of the trash is missing or too long. */
    public const string REASON = 'reason_invalid';

    private const string PREFIX = 'invoice.problem.';

    /** What the data does not allow now, whatever is sent: 409. */
    private const array CONFLICTS = [
        'number_locked', 'number_locked_sent', 'number_locked_sending', 'number_taken', 'trashed', 'tax_document_fixed',
        'changed_meanwhile', 'firm_locked', 'firm_archived', 'already_deducted',
    ];

    /** The fields of IssueRules, as the inputs of the API name them. */
    private const array FIELDS = [
        'firm' => 'firmId', 'series' => 'seriesId', 'customer' => 'customerId', 'document' => null, 'version' => null,
    ];

    /** The fields of a line, as the line objects name them. */
    private const array LINE_FIELDS = ['price' => 'unit_price', 'priceType' => 'price_type', 'supplyCode' => 'supply_code', 'treatment' => 'vat', 'rate' => 'vat'];

    /**
     * @param list<string> $problems
     */
    private function __construct(
        private readonly string $reason,
        private readonly RefusalKind $kind,
        private readonly ?string $field,
        string $message,
        private readonly array $problems = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * @param \Closure(IssueProblem): string $message the message of a problem, written for agents
     */
    public static function of(IssueRefused $refused, \Closure $message): self
    {
        $problems = $refused->problems;
        $first = $problems[0] ?? new IssueProblem('document', self::PREFIX.'invalid');
        $reason = self::reasonOf($first);
        $listed = array_map(static fn (IssueProblem $problem): string => self::wire(self::fieldOf($problem->field) ?? 'document').': '.self::reasonOf($problem), $problems);

        return new self(
            $reason,
            \in_array($reason, self::CONFLICTS, true) ? RefusalKind::Conflict : RefusalKind::Invalid,
            self::fieldOf($first->field),
            implode(' ', array_map(static fn (IssueProblem $problem): string => rtrim($message($problem), '.').'.', $problems)),
            $listed,
            $refused,
        );
    }

    /**
     * A value of the field the API could not read.
     */
    public static function unreadable(string $reason, string $field, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message);
    }

    public static function unknownFirm(?\Throwable $previous = null): self
    {
        return new self(self::FIRM_UNKNOWN, RefusalKind::NotFound, null, 'The instance has no firm yet: its first-run setup has not happened.', previous: $previous);
    }

    public static function notFound(?\Throwable $previous = null): self
    {
        return new self(self::NOT_FOUND, RefusalKind::NotFound, null, 'The organisation has no document with this id. Find the id with foresi_search_invoices.', previous: $previous);
    }

    public static function forbidden(?\Throwable $previous = null): self
    {
        return new self(self::FORBIDDEN, RefusalKind::Forbidden, null, 'Only the owners and members of the organisation work with its invoices.', previous: $previous);
    }

    public static function conflict(string $reason, string $message, ?\Throwable $previous = null): self
    {
        return new self($reason, RefusalKind::Conflict, null, $message, previous: $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return $this->kind;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function details(): array
    {
        return [] === $this->problems ? [] : ['problems' => $this->problems];
    }

    private static function wire(string $field): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
    }

    private static function reasonOf(IssueProblem $problem): string
    {
        return str_starts_with($problem->key, self::PREFIX) ? substr($problem->key, \strlen(self::PREFIX)) : $problem->key;
    }

    /**
     * "lines.2.price" is lines[2].unit_price; a field of the document keeps its
     * name, which the API writes in snake case.
     */
    private static function fieldOf(string $field): ?string
    {
        if (1 === preg_match('/^lines\.(\d+)\.(\w+)$/D', $field, $match)) {
            return \sprintf('lines[%d].%s', $match[1], self::LINE_FIELDS[$match[2]] ?? $match[2]);
        }

        return \array_key_exists($field, self::FIELDS) ? self::FIELDS[$field] : $field;
    }
}
