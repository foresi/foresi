<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * Port: the name a seeded status gets, from the translation key of its
 * workflow template, in the language of the person creating the project.
 */
interface StatusNames
{
    public function of(string $key): string;
}
