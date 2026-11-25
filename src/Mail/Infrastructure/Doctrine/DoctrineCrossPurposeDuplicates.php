<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Foresi\Mail\Contract\CrossPurposeDuplicates;

/**
 * One join over the Message-ID and bytes index. A message without a Message-ID
 * matches nothing, as a null is equal to no value.
 */
final readonly class DoctrineCrossPurposeDuplicates implements CrossPurposeDuplicates
{
    private const string FIND = <<<'SQL'
        SELECT other.id AS message_id, other_address.purpose AS purpose
        FROM mail_message mine
        JOIN mail_inbound_address mine_address ON mine_address.id = mine.address_id
        JOIN mail_message other ON other.message_id_header = mine.message_id_header AND other.raw_sha256 = mine.raw_sha256 AND other.id <> mine.id
        JOIN mail_inbound_address other_address ON other_address.id = other.address_id
        WHERE mine.id = :id AND other_address.purpose <> mine_address.purpose
        ORDER BY other.received_at, other.id
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function of(string $messageId): array
    {
        $found = [];
        foreach ($this->connection->fetchAllAssociative(self::FIND, ['id' => $messageId]) as $row) {
            $found[] = ['messageId' => \is_string($row['message_id']) ? $row['message_id'] : '', 'purpose' => \is_string($row['purpose']) ? $row['purpose'] : ''];
        }

        return $found;
    }
}
