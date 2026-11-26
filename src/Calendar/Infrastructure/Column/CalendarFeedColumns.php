<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Column;

use Foresi\Calendar\Application\View\CalendarFeedLists;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the person's calendar feeds, those the page always showed: the name with a
 * revoked feed flagged, when it was made and when an app last fetched it.
 */
final readonly class CalendarFeedColumns implements DataSource
{
    public const string LIST = CalendarFeedLists::KEY;

    public function __construct(private InstanceClock $clock, private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'calendar.feeds.column.'.$key;
        $created = fn (CalendarFeed $feed): string => $this->format($feed->createdAt());
        $fetched = fn (CalendarFeed $feed): string => $feed->lastFetchedAt() instanceof \DateTimeImmutable ? $this->format($feed->lastFetchedAt()) : $this->translator->trans('calendar.feeds.never');

        return [
            new Attribute('name', $label('name'), AttributeType::Text, static fn (CalendarFeed $feed): string => $feed->name(), defaultVisible: true, defaultPosition: 10, cell: fn (CalendarFeed $feed): array|string => $feed->isRevoked() ? ['text' => $feed->name(), 'flag' => ['label' => $this->translator->trans('calendar.feeds.state.revoked'), 'tone' => 'red']] : $feed->name(), locked: true, truncate: true),
            new Attribute('created', $label('created'), AttributeType::Text, $created, defaultVisible: true, defaultPosition: 20, cell: $created, phone: false),
            new Attribute('last_fetched', $label('last_fetched'), AttributeType::Text, $fetched, defaultVisible: true, defaultPosition: 30, cell: $fetched, aliases: ['lastFetched']),
        ];
    }

    private function format(\DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime'));
    }
}
