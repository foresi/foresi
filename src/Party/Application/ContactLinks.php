<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\ContactLink;

/**
 * Links contact persons to companies, once per pair.
 */
interface ContactLinks
{
    /**
     * @throws \DomainException when the person is linked to the company already
     */
    public function add(ContactLink $link): void;

    /**
     * Removes a stored link; the surrounding unit of work flushes.
     */
    public function remove(ContactLink $link): void;
}
