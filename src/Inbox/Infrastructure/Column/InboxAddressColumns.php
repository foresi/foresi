<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Column;

use Foresi\Inbox\Application\View\InboxAddressLists;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\OwnAddress;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the person's inbox addresses, those the page always showed: the label, the
 * masked address, when it was made and how many mails were dropped today.
 */
final readonly class InboxAddressColumns implements DataSource
{
    public const string LIST = InboxAddressLists::KEY;

    public function __construct(private InstanceClock $clock, private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'inbox.account.column.'.$key;
        $created = fn (OwnAddress $address): string => $address->createdAt->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime'));
        $dropped = fn (OwnAddress $address): string => $this->translator->trans('inbox.account.dropped', ['count' => $address->droppedToday]);

        return [
            new Attribute('label', $label('label'), AttributeType::Text, static fn (OwnAddress $address): string => $address->label, defaultVisible: true, defaultPosition: 10, cell: static fn (OwnAddress $address): string => $address->label, locked: true, truncate: true),
            new Attribute('address', $label('address'), AttributeType::Text, static fn (OwnAddress $address): string => $address->maskedAddress, defaultVisible: true, defaultPosition: 20, cell: static fn (OwnAddress $address): string => $address->maskedAddress, phone: false),
            new Attribute('created', $label('created'), AttributeType::Text, $created, defaultVisible: true, defaultPosition: 30, cell: $created, phone: false),
            new Attribute('dropped', $label('dropped'), AttributeType::Text, $dropped, defaultVisible: true, defaultPosition: 40, cell: $dropped),
        ];
    }
}
