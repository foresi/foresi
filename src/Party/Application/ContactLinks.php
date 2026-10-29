<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\ContactLink;

/**
 * Links contact persons to companies, once per pair.
 */
interface ContactLinks
{
    /**
     * @throws \DomainException when the person is linked to the company already
     */
    public function add(ContactLink $link): void;
}
