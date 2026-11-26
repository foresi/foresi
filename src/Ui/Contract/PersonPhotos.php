<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The photos of the people who use Foresi, for the Ui:Avatar given a user id.
 * A module that keeps the photos implements it; it answers a whole list of
 * avatars from what it read once, never with a lookup each. Every
 * implementation is picked up automatically (tag "foresi.ui.person_photos");
 * without one, every avatar keeps its initials.
 */
#[AutoconfigureTag(self::TAG)]
interface PersonPhotos
{
    public const string TAG = 'foresi.ui.person_photos';

    /**
     * The address of the photo of the user, which needs a sign-in; null when they have none.
     */
    public function url(string $userId): ?string;
}
