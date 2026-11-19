<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\View;

use Foresi\Invoicing\Domain\SendPurpose;
use Foresi\Invoicing\Domain\SendStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The send log and the views it ships: the tabs it had, each the same sends as
 * before (spec D12), All first. The log counted none, so no view shows a count (D9).
 * The type of a send, an invoice or a reminder, was a select on the bar and is a
 * field of "+ Filter" now (D11). The document's number, its customer and when a send was
 * made are filterable attributes of the log's data source (DS1).
 */
final class SendLogLists implements ListDefinitionSource
{
    public const string KEY = 'invoicing.sends';
    public const string STATUS = 'status';
    public const string PURPOSE = 'purpose';

    /** The tabs the log had, by the ?status= they were opened with: the slugs of its views. */
    public const array TABS = [SendStatus::Sent, SendStatus::Queued, SendStatus::Failed];

    public function lists(): iterable
    {
        yield $this->list();
    }

    private ?ListDefinition $list = null;

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function list(): ListDefinition
    {
        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, $this->ownFields()), $this->defaults());
    }

    /**
     * @return list<Field>
     */
    private function ownFields(): array
    {
        $statuses = array_column(SendStatus::cases(), 'value');
        $purposes = array_column(SendPurpose::cases(), 'value');

        return [
            new Field(self::STATUS, FieldType::Enum, 'invoice.sends.column.status', options: $statuses, optionLabels: $this->labels('invoice.sends.status.', $statuses)),
            new Field(self::PURPOSE, FieldType::Enum, 'reminder.log.column', options: $purposes, optionLabels: $this->labels('reminder.log.purpose.', $purposes)),
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private function labels(string $prefix, array $values): array
    {
        return array_combine($values, array_map(static fn (string $value): string => $prefix.$value, $values));
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        $views = [new DefaultView(ListDefinition::ALL, 'invoice.sends.tab.all', showCount: false, position: 0)];
        foreach (self::TABS as $position => $status) {
            $views[] = new DefaultView($status->value, 'invoice.sends.tab.'.$status->value, ViewFilter::all(new Condition(self::STATUS, 'is', $status->value)), showCount: false, position: $position + 1);
        }

        return $views;
    }
}
