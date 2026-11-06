<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/**
 * A request the importer may not send: not a read, or not one of the paths it
 * reads. Thrown before anything leaves the application.
 */
final class IdokladPathRefused extends \DomainException
{
}
