<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\InvoicingEmail;
use App\Party\Domain\PartyRole;

/**
 * What FirmSettingsReader collects while it goes through the scopes; the
 * fields mean what they mean on FirmSettingsSubmission.
 *
 * @internal
 *
 * @phpstan-import-type Values from FirmSettingsSubmission
 */
final class FirmSettingsParsed
{
    /** @var array<string, Values> */
    public array $values = [];

    /** @var array<string, array<string, bool>> */
    public array $own = [];

    /** @var array<string, array<string, string>> */
    public array $errors = [];

    /** @var array<string, list<PartyRole>|null> */
    public array $roles = [];

    /** @var array<string, InvoicingDefaults|null> */
    public array $defaults = [];

    /** @var array<string, list<InvoicingEmail>|null> */
    public array $emails = [];

    /** @var array<string, bool> */
    public array $archived = [];
}
