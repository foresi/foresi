<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Port: writes what the persistence session holds to the database, inside the
 * transaction that is running, without ending it. A unit of work inside another
 * one leaves storing to the outermost; a step that reads back by plain
 * statements what the step before it added, such as a row lock on a movement
 * just recorded, needs it stored first.
 */
interface PendingChanges
{
    public function store(): void;
}
