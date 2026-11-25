<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

/**
 * The store refused an address because the person has an active one for the
 * purpose already: two requests at once met at the partial unique index.
 */
final class AddressAlreadyActive extends \DomainException
{
}
