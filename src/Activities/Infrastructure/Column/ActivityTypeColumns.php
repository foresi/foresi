<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Column;

use Foresi\Activities\Application\View\ActivityTypeLists;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Activities\Infrastructure\Screen\ActivityScreen;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the activity types on Settings → Activities, those the page always showed: the
 * name, the timing with its default duration and the archive, and the key.
 */
final readonly class ActivityTypeColumns implements DataSource
{
    public const string LIST = ActivityTypeLists::KEY;

    public function __construct(private ActivityScreen $screen, private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'activities.settings.column.'.$key;
        $name = fn (ActivityType $type): string => $this->screen->typeName($type);
        $timing = fn (ActivityType $type): string => $this->translator->trans('activities.timing.'.$type->timing()->value);

        return [
            new Attribute('name', $label('name'), AttributeType::Text, $name, defaultVisible: true, defaultPosition: 10, cell: $name, locked: true, truncate: true),
            new Attribute('timing', $label('timing'), AttributeType::Text, $timing, defaultVisible: true, defaultPosition: 20, cell: function (ActivityType $type) use ($timing): array {
                $minutes = $type->defaultDurationMinutes();
                $badges = [['label' => null === $minutes ? $timing($type) : $timing($type).' · '.$this->translator->trans('activities.duration', ['minutes' => $minutes]), 'tone' => 'gray']];
                if ($type->isArchived()) {
                    $badges[] = ['label' => $this->translator->trans('activities.settings.archived'), 'tone' => 'gray'];
                }

                return ['badges' => $badges];
            }),
            new Attribute('key', $label('key'), AttributeType::Text, static fn (ActivityType $type): string => $type->key(), defaultVisible: true, defaultPosition: 30, cell: static fn (ActivityType $type): string => $type->key(), phone: false),
        ];
    }
}
