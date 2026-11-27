<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\View;

use Foresi\Purchasing\Domain\MailIntakeOutcome;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The recent e-mails of the person's capture address, on Settings → Received documents, as a
 * list with views (docs/design/list-pages.md, rule 8). Its one shipped view is All.
 */
final class CaptureMailLists implements ListDefinitionSource
{
    public const string KEY = 'purchasing.capture_mails';
    public const string FROM = 'from';
    public const string SUBJECT = 'subject';
    public const string OUTCOME = 'outcome';

    private ?ListDefinition $list = null;

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function lists(): iterable
    {
        yield $this->list();
    }

    public function list(): ListDefinition
    {
        $outcomes = array_map(static fn (MailIntakeOutcome $outcome): string => $outcome->value, MailIntakeOutcome::cases());

        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::FROM, FieldType::Text, 'purchasing.capture.recent.column.from'),
            new Field(self::SUBJECT, FieldType::Text, 'purchasing.capture.recent.column.subject'),
            new Field(self::OUTCOME, FieldType::Enum, 'purchasing.capture.recent.column.outcome', options: $outcomes, optionLabels: array_combine($outcomes, array_map(static fn (string $outcome): string => 'purchasing.capture.recent.outcome.'.$outcome, $outcomes))),
        ]), [new DefaultView(ListDefinition::ALL, 'purchasing.capture.recent.tab.all', showCount: false, position: 0)]);
    }
}
