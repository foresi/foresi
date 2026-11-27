<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application\View;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The activity types on Settings → Activities as a list with views (docs/design/list-pages.md,
 * rule 8). Its one shipped view is All, which shows the archived ones too, as the page always
 * did; a person makes their own views on the name, the timing and the archive.
 */
final class ActivityTypeLists implements ListDefinitionSource
{
    public const string KEY = 'activities.types';
    public const string NAME = 'name';
    public const string TIMING = 'timing';
    public const string ARCHIVED = 'archived';

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
        $timings = array_map(static fn (ActivityTiming $timing): string => $timing->value, ActivityTiming::cases());

        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::NAME, FieldType::Text, 'activities.settings.field.name'),
            new Field(self::TIMING, FieldType::Enum, 'activities.settings.field.timing', options: $timings, optionLabels: array_combine($timings, array_map(static fn (string $timing): string => 'activities.timing.'.$timing, $timings))),
            new Field(self::ARCHIVED, FieldType::Boolean, 'activities.settings.archived'),
        ]), [new DefaultView(ListDefinition::ALL, 'activities.settings.tab.all', showCount: false, position: 0)]);
    }
}
