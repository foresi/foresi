<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * The one way bank movements enter Foresi, a service Banking provides: a statement
 * file read by a StatementFormat, a bank API connector's scheduled job, and
 * Banking's own bank mail all submit here. Behind it, once for every channel:
 *
 * - an entry the same source told before, by the same bank reference, is skipped
 *   as already known; without a reference, an entry equal to one of another
 *   statement of the source is recorded as a possible duplicate, which never pays;
 * - an entry another source recorded already (one movement of the account with the
 *   same direction, amount, booked day and variable symbol, and counter-account
 *   when both state one, not confirmed by a third source yet) confirms that movement
 *   instead of adding a second one; its trust rises when this source is trusted
 *   more, and a movement that waited for being unverified is paired again. Two or
 *   more such movements make the entry a possible duplicate for a person. What the
 *   bank said first is never rewritten;
 * - the rest is recorded and each movement paired when the rules leave one answer
 *   and its trust allows;
 * - a statement that is not a bank mail is stored, and the statements of the
 *   account from the source are checked for a gap: an opening balance that is not
 *   the previous closing one, a number missing, or a statement that does not add
 *   up. A gap is a warning; the movements are recorded all the same. The same file
 *   of the same source submitted again is taken once: the second time nothing
 *   changes and every entry counts as already known.
 *
 * Call it in one go per statement; it runs in a transaction of its own, or in the
 * caller's.
 */
interface StatementIntake
{
    /**
     * @param StatementFile|null $file the file the statement was read from, whose hash and name are kept with it
     *
     * @throws StatementRefused when the statement is not of an active bank account of the firm
     */
    public function submit(BankStatement $statement, MovementProvenance $provenance, ?StatementFile $file = null): IntakeResult;
}
