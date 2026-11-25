<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A format of bank statement files, such as camt.053, MT940 or ABO/GPC. A country
 * module or a format module adds one; Banking picks the one format that supports a
 * file and hands what it read to the StatementIntake. A format reads; it decides
 * nothing about trust, accounts or duplicates.
 */
#[AutoconfigureTag(self::TAG)]
interface StatementFormat
{
    public const string TAG = 'foresi.banking.statement_format';

    /**
     * A stable identifier, stored with every statement and movement it read: lowercase
     * letters, digits, dots, dashes and underscores, at most 64.
     */
    public function id(): string;

    public function version(): int;

    /**
     * The name people know the format by, such as "camt.053".
     */
    public function label(): string;

    /**
     * Whether the file looks like one of this format. Cheap: it reads the start of the file.
     */
    public function supports(StatementFile $file): bool;

    /**
     * The statements in the file, one per account and period, in the order of the file.
     *
     * @return list<BankStatement>
     *
     * @throws ParseFailed when the file is of this format but cannot be read safely
     */
    public function read(StatementFile $file): array;
}
