<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Dbal;

use App\Events\Application\Query\EventRow;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * Turns a positioned row of the log, as DBAL fetched it, into an event row, for
 * the feed and for the sinks of the relay alike.
 */
final class EventRowReader
{
    /** The columns an event row is read from. */
    public const array COLUMNS = [
        EventLogTable::ID,
        EventLogTable::NAME,
        EventLogTable::VERSION,
        EventLogTable::POSITION,
        EventLogTable::OCCURRED_AT,
        EventLogTable::SUBJECT_TYPE,
        EventLogTable::SUBJECT_ID,
        EventLogTable::DATA,
        EventLogTable::CORRELATION_ID,
        EventLogTable::ACTOR_KIND,
    ];

    /**
     * @param array<string, mixed> $row
     */
    public static function read(array $row, AbstractPlatform $platform): EventRow
    {
        $facts = Type::getType(Types::JSON)->convertToPHPValue($row[EventLogTable::DATA], $platform);
        $occurred = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row[EventLogTable::OCCURRED_AT], $platform);
        if (!$occurred instanceof \DateTimeImmutable || !\is_array($facts)) {
            throw new \UnexpectedValueException('The event log holds a row of an unexpected shape.');
        }
        $correlation = $row[EventLogTable::CORRELATION_ID];
        $named = [];
        foreach ($facts as $name => $fact) {
            $named[(string) $name] = $fact;
        }

        return new EventRow(
            self::text($row[EventLogTable::ID]),
            self::text($row[EventLogTable::NAME]),
            self::number($row[EventLogTable::VERSION]),
            self::number($row[EventLogTable::POSITION]),
            $occurred,
            self::text($row[EventLogTable::SUBJECT_TYPE]),
            self::text($row[EventLogTable::SUBJECT_ID]),
            $named,
            null === $correlation ? null : self::text($correlation),
            self::text($row[EventLogTable::ACTOR_KIND]),
        );
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('The event log holds a column of an unexpected type.');
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('The event log holds a column of an unexpected type.');
    }
}
