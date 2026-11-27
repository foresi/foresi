<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Infrastructure\Identity;

use Foresi\Identity\Contract\PersonContactPicker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The address book offers the people screens its contact picker, so the owner may
 * link the contact a person represents.
 */
final readonly class RoutedPersonContactPicker implements PersonContactPicker
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function searchUrl(): string
    {
        return $this->urls->generate(PeopleContactsController::ROUTE);
    }
}
