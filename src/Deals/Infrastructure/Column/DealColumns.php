<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Column;

use Foresi\Deals\Application\View\DealLists;
use Foresi\Deals\Application\View\DealRow;
use Foresi\Deals\Domain\DealStatus;
use Foresi\Deals\Infrastructure\Screen\DealScreen;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;

/**
 * The columns of the deals; each cell renders one deal: its title, client,
 * owner, value and status. The stage is the section the row sits in.
 */
final readonly class DealColumns implements DataSource
{
    public const string LIST = DealLists::KEY;

    public function __construct(private DealScreen $screen)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'deals.deals.column.'.$key;
        $statuses = array_column(DealStatus::cases(), 'value');

        return [
            new Attribute('title', $label('title'), AttributeType::Text, static fn (DealRow $row): string => $row->deal->title(), sort: 'd.title', defaultVisible: true, defaultPosition: 10, cell: static fn (DealRow $row): string => $row->deal->title(), locked: true, truncate: true, filterable: true, filter: 'd.title'),
            new Attribute('client', $label('client'), AttributeType::Text, fn (DealRow $row): string => $this->screen->client($row->deal->clientPartyId()), defaultVisible: true, defaultPosition: 20, cell: fn (DealRow $row): string => $this->screen->client($row->deal->clientPartyId()), truncate: true),
            new Attribute('owner', $label('owner'), AttributeType::Text, fn (DealRow $row): string => $this->screen->owner($row->deal->ownerUserId()), defaultVisible: true, defaultPosition: 30, cell: fn (DealRow $row): string => $this->screen->owner($row->deal->ownerUserId()), phone: false),
            new Attribute('value', $label('value'), AttributeType::Text, fn (DealRow $row): string => $this->screen->value($row->value), defaultVisible: true, defaultPosition: 40, cell: fn (DealRow $row): string => $this->screen->value($row->value), wide: true),
            new Attribute('status', $label('status'), AttributeType::Enum, static fn (DealRow $row): DealStatus => $row->deal->status(), defaultVisible: true, defaultPosition: 50, options: array_combine($statuses, array_map(static fn (string $status): string => 'deals.status.'.$status, $statuses)), tones: [DealStatus::Won->value => 'green', DealStatus::Lost->value => 'red']),
        ];
    }
}
